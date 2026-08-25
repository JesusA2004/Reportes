<?php

namespace App\Services\Radiography;

/**
 * Genera gráficas server-side como SVG inline para el PDF de radiografía (dompdf).
 *
 * Por qué SVG y no una librería de charts: dompdf (el renderer PDF vigente de este
 * proyecto, ver RadiografiaExportService — Barryvdh\DomPDF) no ejecuta JavaScript ni
 * canvas, así que cualquier librería de charts basada en JS (Chart.js, ApexCharts,
 * etc.) queda fuera. dompdf SÍ renderiza SVG inline razonablemente bien. Esta clase
 * genera el marcado SVG directamente en PHP a partir del MISMO dataset canónico ya
 * calculado (nunca recalcula nada financiero) — cero dependencias nuevas, cero
 * Chromium, huella de memoria mínima (son cadenas de texto, no imágenes rasterizadas).
 *
 * Nunca genera un gráfico para datos vacíos — cada método devuelve '' si no hay nada
 * que mostrar, y el llamador (blade) debe omitir la sección completa en ese caso.
 */
class RadiographyChartSvgBuilder
{
    private const COLORS = ['#106A59', '#1f2937', '#0ea5e9', '#f59e0b', '#dc2626', '#7c3aed', '#059669', '#64748b'];

    /**
     * Gráfica de barras horizontales — sirve tanto para comparaciones de 2 valores
     * (Recuperación vs Colocación, EBITDA vs Gastos, Cartera sana vs vencida) como
     * para desgloses de N categorías (mora por bucket, composición de nómina,
     * colocación/recuperación por producto, efectividad de cobranza).
     *
     * @param array<int, array{label: string, value: float}> $data
     */
    public function horizontalBarChart(array $data, string $title, int $width = 480, int $rowHeight = 22): string
    {
        $data = array_values(array_filter($data, fn ($d) => (float) $d['value'] > 0));
        if (empty($data)) {
            return '';
        }

        // Máximo 10 barras — más que eso deja de ser legible en el ancho de una hoja carta.
        $data = array_slice($data, 0, 10);

        $max = max(array_column($data, 'value'));
        if ($max <= 0) {
            return '';
        }

        $labelWidth = 150;
        $barAreaWidth = $width - $labelWidth - 70;
        $height = count($data) * $rowHeight + 30;

        $svg = sprintf(
            '<svg width="%d" height="%d" viewBox="0 0 %d %d" xmlns="http://www.w3.org/2000/svg" style="font-family: Helvetica, Arial, sans-serif;">',
            $width, $height, $width, $height
        );
        $svg .= sprintf('<text x="0" y="14" font-size="9" font-weight="bold" fill="#1f2937">%s</text>', $this->esc($title));

        foreach ($data as $i => $row) {
            $y = 24 + $i * $rowHeight;
            $barWidth = max(2, round(($row['value'] / $max) * $barAreaWidth));
            $color = self::COLORS[$i % count(self::COLORS)];

            $svg .= sprintf('<text x="0" y="%d" font-size="7.5" fill="#334155">%s</text>', $y + 11, $this->esc($this->truncate($row['label'], 26)));
            $svg .= sprintf(
                '<rect x="%d" y="%d" width="%d" height="14" fill="%s" rx="2"/>',
                $labelWidth, $y, $barWidth, $color
            );
            $svg .= sprintf(
                '<text x="%d" y="%d" font-size="7.5" fill="#1e293b">%s</text>',
                $labelWidth + $barWidth + 5, $y + 11, $this->esc($this->formatMoney($row['value']))
            );
        }

        $svg .= '</svg>';

        return $this->wrapAsImg($svg, $width, $height);
    }

    /**
     * Gráfica de dona/pastel simplificada como serie de barras apiladas horizontales
     * con leyenda — usada para "composición" (nómina, cartera sana vs vencida) donde
     * interesa la proporción del total más que comparar magnitudes absolutas.
     *
     * @param array<int, array{label: string, value: float}> $data
     */
    public function stackedCompositionBar(array $data, string $title, int $width = 480): string
    {
        $data = array_values(array_filter($data, fn ($d) => (float) $d['value'] > 0));
        if (empty($data)) {
            return '';
        }

        $total = array_sum(array_column($data, 'value'));
        if ($total <= 0) {
            return '';
        }

        $barHeight = 22;
        $legendRowHeight = 13;
        $height = 30 + $barHeight + (count($data) * $legendRowHeight) + 6;

        $svg = sprintf(
            '<svg width="%d" height="%d" viewBox="0 0 %d %d" xmlns="http://www.w3.org/2000/svg" style="font-family: Helvetica, Arial, sans-serif;">',
            $width, $height, $width, $height
        );
        $svg .= sprintf('<text x="0" y="14" font-size="9" font-weight="bold" fill="#1f2937">%s</text>', $this->esc($title));

        $x = 0;
        $barY = 22;
        foreach ($data as $i => $row) {
            $segWidth = max(1, round(($row['value'] / $total) * $width));
            $color = self::COLORS[$i % count(self::COLORS)];
            $svg .= sprintf('<rect x="%d" y="%d" width="%d" height="%d" fill="%s"/>', $x, $barY, $segWidth, $barHeight, $color);
            $x += $segWidth;
        }

        $legendY = $barY + $barHeight + 12;
        foreach ($data as $i => $row) {
            $color = self::COLORS[$i % count(self::COLORS)];
            $pct = round(($row['value'] / $total) * 100, 1);
            $svg .= sprintf('<rect x="0" y="%d" width="8" height="8" fill="%s"/>', $legendY - 7, $color);
            $svg .= sprintf(
                '<text x="12" y="%d" font-size="7.5" fill="#334155">%s — %s (%s%%)</text>',
                $legendY, $this->esc($this->truncate($row['label'], 32)), $this->esc($this->formatMoney($row['value'])), $pct
            );
            $legendY += 13;
        }

        $svg .= '</svg>';

        return $this->wrapAsImg($svg, $width, $height);
    }

    /**
     * dompdf (v3.1.5, esta instalación) no renderiza <svg> inline embebido directamente
     * en el flujo HTML — lo trata como marcado desconocido y solo imprime los nodos de
     * texto en línea, descartando los <rect>/<path> (verificado empíricamente: ver
     * commit que introduce este método). Envolver el mismo SVG como
     * <img src="data:image/svg+xml;base64,...">, en cambio, sí lo rasteriza/renderiza
     * correctamente vía el adaptador de imágenes de dompdf. Mismo SVG, mismo dataset —
     * solo cambia el contenedor HTML que dompdf sabe interpretar.
     */
    private function wrapAsImg(string $svg, int $width, int $height): string
    {
        $b64 = base64_encode($svg);

        return sprintf(
            '<img src="data:image/svg+xml;base64,%s" width="%d" height="%d" style="display:block;" />',
            $b64, $width, $height
        );
    }

    private function truncate(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1) . '…' : $value;
    }

    private function formatMoney(float $value): string
    {
        return '$' . number_format($value, 0);
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
