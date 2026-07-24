<?php

namespace App\Services\Radiography;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\ConditionalFormatting\ConditionalDataBar;
use PhpOffice\PhpSpreadsheet\Style\ConditionalFormatting\ConditionalFormatValueObject;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Centralized styling for the Radiografía workbook/sheets. Every sheet builder
 * (GLOBAL, branch sheets, detail sheets) goes through this class so the whole
 * workbook shares one palette and one set of formatting rules.
 */
final class RadiographyStyleHelper
{
    // Palette — teal/mint executive look, matches the reference workbook's aesthetic.
    public const BG_PRIMARY_DARK = 'FF106A59';
    public const BG_ACCENT       = 'FF1DC1A2';
    public const BG_LIGHT_BLUE   = 'FFE3EFFF';
    public const BG_SECTION_HDR  = 'FF5B9BD5';
    public const BG_GRAY         = 'FFF2F2F2';
    public const BG_WHITE        = 'FFFFFFFF';
    public const BG_POSITIVE     = 'FFC6EFCE';
    public const BG_ALERT_RED    = 'FFFFC7CE';
    public const BG_ALERT_YELLOW = 'FFFFEB9C';
    public const FG_STRONG_GREEN = 'FF00B050';
    public const FG_DARK_TEXT    = 'FF1F2937';
    public const FG_WHITE        = 'FFFFFFFF';
    public const FG_RED          = 'FFB91C1C';
    public const FG_AMBER        = 'FF92400E';
    public const BG_AMBER        = 'FFFEF3C7';
    public const HYPERLINK_BLUE  = 'FF1D4ED8';
    public const BORDER_LT       = 'FFD9E2EC';

    // EBITDA category thresholds — single source of truth shared by PDF and Excel.
    public const EBITDA_DIAMANTE_MIN = 1_000_000.0;
    public const EBITDA_MASTER_MIN   =   600_000.0;
    public const EBITDA_SENIOR_MIN   =   300_000.0;
    public const EBITDA_JUNIOR_MIN   =   100_000.0;

    public const CURRENCY = '"$"#,##0.00';
    public const PERCENT  = '0.00"%"';
    public const INTEGER  = '#,##0';
    public const DATE_FMT = 'DD/MM/YYYY';

    public static function applyTitleStyle(Worksheet $sheet, string $range, string $text): void
    {
        self::mergeCellsSafe($sheet, $range);
        self::setCellValueSafe($sheet, explode(':', $range)[0], $text);
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'size' => 16, 'color' => ['argb' => self::FG_WHITE]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_PRIMARY_DARK]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);
        preg_match('/(\d+)/', $range, $m);
        if (!empty($m[1])) {
            $sheet->getRowDimension((int)$m[1])->setRowHeight(32);
        }
    }

    public static function applySubtitleStyle(Worksheet $sheet, string $range, string $text): void
    {
        self::mergeCellsSafe($sheet, $range);
        self::setCellValueSafe($sheet, explode(':', $range)[0], $text);
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => self::FG_DARK_TEXT]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_LIGHT_BLUE]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);
        preg_match('/(\d+)/', $range, $m);
        if (!empty($m[1])) {
            $sheet->getRowDimension((int)$m[1])->setRowHeight(22);
        }
    }

    public static function applyMetaStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['size' => 9, 'color' => ['argb' => self::FG_DARK_TEXT]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_GRAY]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);
    }

    public static function applySectionHeaderStyle(Worksheet $sheet, string $range, string $text): void
    {
        self::mergeCellsSafe($sheet, $range);
        self::setCellValueSafe($sheet, explode(':', $range)[0], $text);
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13.5, 'color' => ['argb' => self::FG_WHITE]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_SECTION_HDR]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);
        preg_match('/(\d+)/', $range, $m);
        if (!empty($m[1])) {
            $sheet->getRowDimension((int)$m[1])->setRowHeight(22);
        }
    }

    /**
     * @param array<string,string> $cols column letter => header label
     */
    public static function applyTableHeaderStyle(Worksheet $sheet, int $row, array $cols, bool $accent = false): void
    {
        foreach ($cols as $col => $label) {
            self::setCellValueSafe($sheet, "{$col}{$row}", $label);
        }
        $keys  = array_keys($cols);
        $range = reset($keys) . $row . ':' . end($keys) . $row;
        $bg    = $accent ? self::BG_ACCENT : self::BG_LIGHT_BLUE;
        $fg    = $accent ? self::FG_WHITE : self::FG_DARK_TEXT;
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => $fg]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
            'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => self::BG_PRIMARY_DARK]]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(18);
    }

    public static function applyDataRowStyle(Worksheet $sheet, string $range, bool $even): void
    {
        $bg = $even ? self::BG_WHITE : self::BG_GRAY;
        $sheet->getStyle($range)->applyFromArray([
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
            'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => self::BORDER_LT]]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle($range)->getFont()->setSize(9);
        preg_match('/[A-Z]+(\d+)/', $range, $m);
        if (!empty($m[1])) {
            $row = (int)$m[1];
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->getRowDimension($row)->setRowHeight(17);
        }
    }

    public static function applyTotalRowStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9.5, 'color' => ['argb' => self::FG_WHITE]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_PRIMARY_DARK]],
            'borders'   => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => self::BG_ACCENT]]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        preg_match('/[A-Z]+(\d+)/', $range, $m);
        if (!empty($m[1])) {
            $sheet->getRowDimension((int)$m[1])->setRowHeight(20);
        }
    }

    public static function applyCurrencyFormat(Worksheet $sheet, string $cell): void
    {
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::CURRENCY);
        $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    public static function applyPercentFormat(Worksheet $sheet, string $cell, ?float $value = null): void
    {
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::PERCENT);
        $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        if ($value !== null && $value > 25) {
            $font = $sheet->getStyle($cell)->getFont();
            $font->setBold(true);
            $font->getColor()->setARGB(self::FG_RED);
        }
    }

    public static function applyIntegerFormat(Worksheet $sheet, string $cell): void
    {
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::INTEGER);
        $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    public static function applyDateFormat(Worksheet $sheet, string $cell): void
    {
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::DATE_FMT);
        $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    public static function applyWarningStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_ALERT_YELLOW]],
            'font' => ['color' => ['argb' => self::FG_DARK_TEXT]],
        ]);
    }

    public static function applyAlertStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_ALERT_RED]],
            'font' => ['color' => ['argb' => self::FG_RED]],
        ]);
    }

    public static function applyPositiveNegativeStyle(Worksheet $sheet, string $cell, float $value): void
    {
        $sheet->getStyle($cell)->applyFromArray([
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['argb' => $value >= 0 ? self::BG_POSITIVE : self::BG_ALERT_RED],
            ],
            'font' => ['color' => ['argb' => $value >= 0 ? self::FG_STRONG_GREEN : self::FG_RED], 'bold' => true],
        ]);
    }

    /**
     * Writes a cell value safely: strings starting with "=" are written as
     * literal text (never interpreted as a formula), numbers/bools pass
     * through, null becomes an empty string for display purposes.
     */
    public static function setCellValueSafe(Worksheet $sheet, string $cell, mixed $value): void
    {
        if ($value === null) {
            $sheet->setCellValue($cell, '');
            return;
        }
        if (is_string($value) && str_starts_with($value, '=')) {
            $sheet->getCell($cell)->setValueExplicit($value, DataType::TYPE_STRING);
            return;
        }
        $sheet->setCellValue($cell, $value);
    }

    /**
     * Sanitizes/truncates a sheet name to Excel's 31-char limit (using 28 as the
     * working cutoff to leave headroom for collision suffixes) and guarantees
     * uniqueness against the names already used in this workbook.
     *
     * @param array<int,string> $alreadyUsed sheet names already assigned (case-insensitive)
     */
    public static function safeSheetName(string $rawName, array $alreadyUsed = []): string
    {
        $clean = preg_replace('/[\/\\\\\?\*\[\]:]/', '-', trim($rawName));
        $clean = trim((string) $clean) ?: 'Hoja';
        $base  = mb_substr($clean, 0, 28);

        $used = array_map('mb_strtolower', $alreadyUsed);
        if (!in_array(mb_strtolower($base), $used, true)) {
            return $base;
        }

        for ($i = 2; $i < 100; $i++) {
            $suffix = " ({$i})";
            $candidate = mb_substr($clean, 0, 31 - mb_strlen($suffix)) . $suffix;
            if (!in_array(mb_strtolower($candidate), $used, true)) {
                return $candidate;
            }
        }

        return mb_substr($clean, 0, 28) . '-' . substr(md5($rawName), 0, 2);
    }

    /**
     * Quotes a sheet name for use inside an Excel "sheet://" internal hyperlink
     * URL. Excel requires single-quoting any sheet name containing spaces or
     * other special characters; internal quotes are doubled per Excel convention.
     */
    public static function quoteSheetForHyperlink(string $sheetName): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $sheetName) === 1) {
            return $sheetName;
        }
        return "'" . str_replace("'", "''", $sheetName) . "'";
    }

    /**
     * Writes a styled internal hyperlink (label text + blue underline) pointing
     * at another sheet in the same workbook.
     */
    public static function applyHyperlinkStyle(
        Worksheet $sheet,
        string $cell,
        string $label,
        string $targetSheetName,
        string $targetCell = 'A1'
    ): void {
        self::setCellValueSafe($sheet, $cell, $label);
        $quoted = self::quoteSheetForHyperlink($targetSheetName);
        $sheet->getCell($cell)->getHyperlink()->setUrl("sheet://{$quoted}!{$targetCell}");
        $sheet->getStyle($cell)->applyFromArray([
            'font' => [
                'color'     => ['argb' => self::HYPERLINK_BLUE],
                'underline' => true,
            ],
        ]);
    }

    /**
     * @param array<string,float|int> $widths column letter => width
     */
    public static function setColWidths(Worksheet $sheet, array $widths): void
    {
        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
    }

    /**
     * Safely merges a cell range, refusing to merge when it would corrupt the
     * workbook: degenerate single-cell ranges, ranges that overlap an already
     * merged range on this sheet, or ranges that overlap the sheet's
     * autofilter header. Excel silently "repairs" (deletes) any merge that
     * violates these rules, which is exactly the corruption this guards against.
     *
     * @return bool true if the merge was applied, false if it was skipped
     */
    public static function mergeCellsSafe(Worksheet $sheet, string $range): bool
    {
        $range = strtoupper($range);
        [$start, $end] = array_pad(explode(':', $range), 2, null);
        $end ??= $start;
        if ($start === $end) {
            return false; // merging a single cell is meaningless and risks colliding with a wider merge later
        }
        $normalized = "{$start}:{$end}";

        foreach ($sheet->getMergeCells() as $existing) {
            if (self::rangesOverlap($normalized, $existing)) {
                return false;
            }
        }

        $autoFilterRange = $sheet->getAutoFilter()->getRange();
        if ($autoFilterRange !== '' && self::rangesOverlap($normalized, $autoFilterRange)) {
            return false;
        }

        $sheet->mergeCells($normalized);
        return true;
    }

    private static function rangesOverlap(string $rangeA, string $rangeB): bool
    {
        [[$aColStart, $aRowStart], [$aColEnd, $aRowEnd]] = Coordinate::rangeBoundaries($rangeA);
        [[$bColStart, $bRowStart], [$bColEnd, $bRowEnd]] = Coordinate::rangeBoundaries($rangeB);

        return $aColStart <= $bColEnd && $aColEnd >= $bColStart
            && $aRowStart <= $bRowEnd && $aRowEnd >= $bRowStart;
    }

    /**
     * Qualifies a same-sheet cell range with its sheet name for use in a chart
     * data series reference (e.g. "'MORAS'!$A$6:$A$11").
     */
    public static function sheetQualifiedRange(Worksheet $sheet, string $range): string
    {
        return self::quoteSheetForHyperlink($sheet->getTitle()) . '!' . $range;
    }

    /**
     * Adds a native, editable Excel bar chart driven entirely by real cell
     * ranges already on the sheet (no images, no external data). Safe to call
     * on any sheet — chart objects are independent of merged cells/autofilters,
     * so this never risks the kind of corruption a bad mergeCells() would.
     *
     * @param string $categoryRange same-sheet range, e.g. "$A$6:$A$11"
     * @param string $valueRange    same-sheet range, e.g. "$C$6:$C$11"
     */
    /**
     * @param string|array<int,string> $barColorArgb single ARGB hex, or one per data point
     */
    public static function addBarChart(
        Worksheet $sheet,
        string $chartTitle,
        string $categoryRange,
        string $valueRange,
        int $dataPointCount,
        string $topLeftCell,
        string $bottomRightCell,
        string|array|null $barColorArgb = null
    ): void {
        $categoriesRef = self::sheetQualifiedRange($sheet, $categoryRange);
        $valuesRef     = self::sheetQualifiedRange($sheet, $valueRange);

        $color = $barColorArgb ?? self::BG_ACCENT;
        $values = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $valuesRef, null, $dataPointCount);
        $values->setFillColor(is_array($color) ? array_map([self::class, 'stripAlpha'], $color) : self::stripAlpha($color));
        $categories = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $categoriesRef, null, $dataPointCount);

        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            [0],
            [],
            [$categories],
            [$values]
        );
        $series->setPlotDirection(DataSeries::DIRECTION_COL);

        $plotArea = new PlotArea(null, [$series]);
        $title    = new Title($chartTitle);

        // No legend: a single-series bar chart gains nothing from a "Series1"
        // legend box — the category axis already labels every bar.
        $chart = new Chart(uniqid('chart_', true), $title, null, $plotArea);
        $chart->setTopLeftPosition($topLeftCell);
        $chart->setBottomRightPosition($bottomRightCell);
        // Full-magnitude axis labels, no thousands-scaling (no "$39,200" standing in for
        // $39,200,570.57) — only decimals are dropped, which is normal for a chart axis tick.
        $chart->getChartAxisY()->setAxisNumberProperties('"$"#,##0', true, false);

        $sheet->addChart($chart);
    }

    /**
     * Two-series clustered bar chart — un valor por categoría para "periodo comparado" y
     * otro para "periodo actual", lado a lado. Usado por el comparativo (mes/bimestre/
     * trimestre vs vs) para que cada métrica se vea gráficamente, no solo en tabla.
     *
     * @param string $categoryRange same-sheet range, e.g. "$A$6:$A$11"
     * @param string $prevValueRange same-sheet range para el periodo comparado
     * @param string $currValueRange same-sheet range para el periodo actual
     */
    public static function addComparativeBarChart(
        Worksheet $sheet,
        string $chartTitle,
        string $categoryRange,
        string $prevValueRange,
        string $currValueRange,
        string $prevLabel,
        string $currLabel,
        int $dataPointCount,
        string $topLeftCell,
        string $bottomRightCell
    ): void {
        $categoriesRef = self::sheetQualifiedRange($sheet, $categoryRange);
        $prevRef       = self::sheetQualifiedRange($sheet, $prevValueRange);
        $currRef       = self::sheetQualifiedRange($sheet, $currValueRange);

        $categories = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $categoriesRef, null, $dataPointCount);

        $prevValues = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $prevRef, null, $dataPointCount);
        $prevValues->setFillColor(self::stripAlpha('FF94A3B8'));

        $currValues = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $currRef, null, $dataPointCount);
        $currValues->setFillColor(self::stripAlpha(self::BG_ACCENT));

        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            [0, 1],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1, [$prevLabel]), new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1, [$currLabel])],
            [$categories, $categories],
            [$prevValues, $currValues]
        );
        $series->setPlotDirection(DataSeries::DIRECTION_COL);

        $plotArea = new PlotArea(null, [$series]);
        $legend   = new \PhpOffice\PhpSpreadsheet\Chart\Legend(\PhpOffice\PhpSpreadsheet\Chart\Legend::POSITION_BOTTOM, null, false);
        $title    = new Title($chartTitle);

        $chart = new Chart(uniqid('chart_', true), $title, $legend, $plotArea);
        $chart->setTopLeftPosition($topLeftCell);
        $chart->setBottomRightPosition($bottomRightCell);
        $chart->getChartAxisY()->setAxisNumberProperties('"$"#,##0', true, false);

        $sheet->addChart($chart);
    }

    private static function stripAlpha(string $argb): string
    {
        return mb_strlen($argb) === 8 ? mb_substr($argb, 2) : $argb;
    }

    /**
     * Applies a native Excel "Data Bar" conditional format to a range — a real,
     * editable Excel feature (gradient bar painted inside the cell, scaled to
     * the min/max of the range), not an image or a chart object. Used for the
     * GLOBAL dashboard instead of native bar charts: PhpSpreadsheet's chart
     * writer can't produce a modern/flat look (always renders with Office's
     * default axis/gridlines/border chrome), while data bars look clean and
     * "dashboard-like" out of the box and can never corrupt the workbook the
     * way a bad merge or freeze pane can.
     */
    /**
     * @param float|null $min explicit scale minimum (type "num"); null = auto ("min" of the range)
     * @param float|null $max explicit scale maximum (type "num"); null = auto ("max" of the range).
     *                        Pass an explicit shared $max across two single-cell ranges to make two
     *                        differently-colored bars visually comparable (e.g. Recuperación vs Colocación).
     */
    public static function addDataBar(
        Worksheet $sheet,
        string $range,
        string $colorArgb,
        bool $showValue = true,
        ?float $min = null,
        ?float $max = null
    ): void {
        $dataBar = new ConditionalDataBar();
        $dataBar->setMinimumConditionalFormatValueObject(
            $min !== null ? new ConditionalFormatValueObject('num', $min) : new ConditionalFormatValueObject('min')
        );
        $dataBar->setMaximumConditionalFormatValueObject(
            $max !== null ? new ConditionalFormatValueObject('num', $max) : new ConditionalFormatValueObject('max')
        );
        $dataBar->setColor(self::stripAlpha($colorArgb));
        $dataBar->setShowValue($showValue);

        $conditional = new Conditional();
        $conditional->setConditionType(Conditional::CONDITION_DATABAR);
        $conditional->setDataBar($dataBar);

        $sheet->getStyle($range)->setConditionalStyles([$conditional]);
    }

    /**
     * EBITDA por sucursal — ÚNICA fórmula, compartida por UI/Excel/PDF. Regla final
     * (2026-07): Ingreso base EBITDA (intereses+impuestos+moratorios+comisión apertura+
     * cargos adicionales+excedentes+30% Seguro CRECE — NUNCA capital recuperado ni
     * recuperación/colocación completas) − Gastos Totales (OPEX + Nómina y Capital
     * Humano). Ver BranchRadiographyCalculator::ebitdaFinalFor() — este método es un
     * alias delgado que delega ahí para que exista una única fuente de verdad.
     *
     * @param array<string,mixed> $branch fila de branch_radiography.branches
     */
    public static function branchEbitdaEstimate(array $branch): float
    {
        return BranchRadiographyCalculator::ebitdaFinalFor($branch);
    }

    /**
     * Margen EBITDA por sucursal — ÚNICA fórmula, alias delgado de
     * BranchRadiographyCalculator::margenEbitdaFor().
     */
    public static function branchMargenEbitda(array $branch): float
    {
        return BranchRadiographyCalculator::margenEbitdaFor($branch);
    }

    /**
     * Categoría de desempeño a partir del EBITDA estimado (ver branchEbitdaEstimate).
     * Umbrales: DIAMANTE ≥1M · MASTER ≥600K · SENIOR ≥300K · JUNIOR ≥100K · MANTENIDO <100K
     */
    public static function ebitdaCategory(float $ebitdaEstimado): string
    {
        return match (true) {
            $ebitdaEstimado >= self::EBITDA_DIAMANTE_MIN => 'DIAMANTE',
            $ebitdaEstimado >= self::EBITDA_MASTER_MIN   => 'MASTER',
            $ebitdaEstimado >= self::EBITDA_SENIOR_MIN   => 'SENIOR',
            $ebitdaEstimado >= self::EBITDA_JUNIOR_MIN   => 'JUNIOR',
            default                                      => 'MANTENIDO',
        };
    }

    /**
     * @return array{bg:string,fg:string} fill/font ARGB pair for a category badge
     */
    public static function categoryColors(string $categoria): array
    {
        return match ($categoria) {
            'DIAMANTE' => ['bg' => 'FFE0F2FE', 'fg' => 'FF0369A1'],
            'MASTER'   => ['bg' => 'FFEDE9FE', 'fg' => 'FF7C3AED'],
            'SENIOR'   => ['bg' => self::BG_POSITIVE, 'fg' => self::FG_STRONG_GREEN],
            'JUNIOR'   => ['bg' => self::BG_AMBER, 'fg' => self::FG_AMBER],
            default    => ['bg' => self::BG_ALERT_RED, 'fg' => self::FG_RED],
        };
    }

    /**
     * Adds a donut (doughnut) chart — preferred over bar charts for showing
     * distribution/composition (how a total is split among parts).
     *
     * @param array<string> $colors  optional ARGB hex colors per slice (without alpha prefix)
     */
    public static function addDonutChart(
        Worksheet $sheet,
        string $chartTitle,
        string $categoryRange,
        string $valueRange,
        int $dataPointCount,
        string $topLeftCell,
        string $bottomRightCell,
        array $colors = []
    ): void {
        $categoriesRef = self::sheetQualifiedRange($sheet, $categoryRange);
        $valuesRef     = self::sheetQualifiedRange($sheet, $valueRange);

        $values     = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $valuesRef, null, $dataPointCount);
        $categories = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $categoriesRef, null, $dataPointCount);

        if (!empty($colors)) {
            $values->setFillColor(array_map([self::class, 'stripAlpha'], $colors));
        }

        $series = new DataSeries(
            DataSeries::TYPE_DONUTCHART,
            null,
            [0],
            [],
            [$categories],
            [$values]
        );

        $plotArea = new PlotArea(null, [$series]);
        $legend   = new \PhpOffice\PhpSpreadsheet\Chart\Legend(
            \PhpOffice\PhpSpreadsheet\Chart\Legend::POSITION_RIGHT,
            null,
            false
        );
        $title = new Title($chartTitle);

        $chart = new Chart(uniqid('chart_', true), $title, $legend, $plotArea);
        $chart->setTopLeftPosition($topLeftCell);
        $chart->setBottomRightPosition($bottomRightCell);

        $sheet->addChart($chart);
    }

    /**
     * Adds a pie chart — use when each slice is truly exclusive and there are
     * few categories (≤6). Prefer donut for ≥5 slices (donut reads better).
     */
    public static function addPieChart(
        Worksheet $sheet,
        string $chartTitle,
        string $categoryRange,
        string $valueRange,
        int $dataPointCount,
        string $topLeftCell,
        string $bottomRightCell,
        array $colors = []
    ): void {
        $categoriesRef = self::sheetQualifiedRange($sheet, $categoryRange);
        $valuesRef     = self::sheetQualifiedRange($sheet, $valueRange);

        $values     = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $valuesRef, null, $dataPointCount);
        $categories = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $categoriesRef, null, $dataPointCount);

        if (!empty($colors)) {
            $values->setFillColor(array_map([self::class, 'stripAlpha'], $colors));
        }

        $series = new DataSeries(
            DataSeries::TYPE_PIECHART,
            null,
            [0],
            [],
            [$categories],
            [$values]
        );

        $plotArea = new PlotArea(null, [$series]);
        $legend   = new \PhpOffice\PhpSpreadsheet\Chart\Legend(
            \PhpOffice\PhpSpreadsheet\Chart\Legend::POSITION_RIGHT,
            null,
            false
        );
        $title = new Title($chartTitle);

        $chart = new Chart(uniqid('chart_', true), $title, $legend, $plotArea);
        $chart->setTopLeftPosition($topLeftCell);
        $chart->setBottomRightPosition($bottomRightCell);

        $sheet->addChart($chart);
    }
}
