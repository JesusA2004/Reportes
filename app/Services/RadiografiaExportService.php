<?php

namespace App\Services;

use App\Models\MonthlyEmployeeSummary;
use App\Models\Period;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class RadiografiaExportService {

    public function export(Period $period): string {
        @ini_set('memory_limit', '1024M');
        @ini_set('max_execution_time', '600');
        @set_time_limit(600);

        $templatePath = $this->resolveTemplatePath();

        $reader = IOFactory::createReaderForFile($templatePath);
        $reader->setReadDataOnly(false);
        $reader->setIncludeCharts(true);

        $spreadsheet = $reader->load($templatePath);

        $globalSheetName = config('radiografia.sheets.global', 'GLOBAL');
        $dashboardSheetName = config('radiografia.sheets.dashboard', 'Dashbord');

        $summary = MonthlyEmployeeSummary::query()
            ->where('period_id', $period->id)
            ->selectRaw('COUNT(*) as total_empleados')
            ->selectRaw('SUM(total_expenses) as gasto_total')
            ->selectRaw('SUM(net_amount) as neto_total')
            ->selectRaw('SUM(total_payments) as pagos_total')
            ->selectRaw('SUM(total_bonuses) as bonos_total')
            ->selectRaw('SUM(total_discounts) as descuentos_total')
            ->first();

        $metrics = [
            'periodo' => $period->label,
            'total_empleados' => (int) ($summary->total_empleados ?? 0),
            'gasto_total' => round((float) ($summary->gasto_total ?? 0), 2),
            'neto_total' => round((float) ($summary->neto_total ?? 0), 2),
            'pagos_total' => round((float) ($summary->pagos_total ?? 0), 2),
            'bonos_total' => round((float) ($summary->bonos_total ?? 0), 2),
            'descuentos_total' => round((float) ($summary->descuentos_total ?? 0), 2),
        ];

        $this->fillSheet(
            $spreadsheet,
            $globalSheetName,
            Arr::get(config('radiografia.maps'), 'GLOBAL', []),
            $metrics,
        );

        $this->fillSheet(
            $spreadsheet,
            $dashboardSheetName,
            Arr::get(config('radiografia.maps'), 'DASHBOARD', []),
            $metrics,
        );

        $directory = storage_path('app/radiografias');
        File::ensureDirectoryExists($directory);

        $outputPath = $directory . '/radiografia_' . ($period->code ?: $period->id) . '.xlsx';

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');

        /*
         * La plantilla oficial puede traer fórmulas complejas que PhpSpreadsheet
         * no sabe calcular correctamente, por ejemplo en Corporativo!C8.
         * No recalculamos fórmulas en PHP; las conservamos para que Excel las calcule al abrir.
         */
        if (method_exists($writer, 'setPreCalculateFormulas')) {
            $writer->setPreCalculateFormulas(false);
        }

        if (method_exists($writer, 'setIncludeCharts')) {
            $writer->setIncludeCharts(true);
        }

        $writer->save($outputPath);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $outputPath;
    }


    public function exportPdf(Period $period): string {
        $directory = storage_path('app/radiografias');
        File::ensureDirectoryExists($directory);
        $outputPath = $directory . '/radiografia_' . ($period->code ?: $period->id) . '.pdf';

        $summary = MonthlyEmployeeSummary::query()
            ->where('period_id', $period->id)
            ->selectRaw('COUNT(*) as total_empleados')
            ->selectRaw('SUM(total_expenses) as gasto_total')
            ->selectRaw('SUM(net_amount) as neto_total')
            ->selectRaw('SUM(total_payments) as pagos_total')
            ->first();

        $lines = [
            'Radiografia ' . $period->label,
            'Periodo: ' . ($period->code ?: $period->id),
            'Empleados: ' . (int) ($summary->total_empleados ?? 0),
            'Pagos: ' . round((float) ($summary->pagos_total ?? 0), 2),
            'Gastos: ' . round((float) ($summary->gasto_total ?? 0), 2),
            'Neto: ' . round((float) ($summary->neto_total ?? 0), 2),
        ];

        $content = implode('\n', $lines);
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $content);
        $stream = "BT /F1 14 Tf 72 760 Td (" . str_replace("\n", ") Tj T* (", $escaped) . ") Tj ET";
        $objects = [
            "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj",
            "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj",
            "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >> endobj",
            "4 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj",
            "5 0 obj << /Length " . strlen($stream) . " >> stream\n" . $stream . "\nendstream endobj",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object . "\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= str_pad((string) $offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }
        $pdf .= "trailer << /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        File::put($outputPath, $pdf);

        return $outputPath;
    }

    private function fillSheet(Spreadsheet $spreadsheet, string $sheetName, array $map, array $metrics): void
    {
        $sheet = $spreadsheet->getSheetByName($sheetName);

        if (!$sheet) {
            return;
        }

        foreach ($map as $metric => $cell) {
            if (array_key_exists($metric, $metrics)) {
                $sheet->setCellValue($cell, $metrics[$metric]);
            }
        }
    }

    private function resolveTemplatePath(): string {
        $candidates = [
            config('radiografia.template_path'),
            resource_path('templates/radiografia_template.xlsx'),
            storage_path('app/templates/radiografia_template.xlsx'),
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && File::exists($candidate)) {
                return $candidate;
            }
        }
        throw new \RuntimeException('No se encontró la plantilla oficial de radiografía en resources/templates o storage/app/templates.');
    }

}
