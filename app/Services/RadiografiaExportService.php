<?php

namespace App\Services;

use App\Models\MonthlyEmployeeSummary;
use App\Models\Period;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\IOFactory;

class RadiografiaExportService
{
    public function export(Period $period): string
    {
        $templatePath = $this->resolveTemplatePath();
        $spreadsheet = IOFactory::load($templatePath);

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

        $this->fillSheet($spreadsheet, $globalSheetName, Arr::get(config('radiografia.maps'), 'GLOBAL', []), $metrics);
        $this->fillSheet($spreadsheet, $dashboardSheetName, Arr::get(config('radiografia.maps'), 'DASHBOARD', []), $metrics);

        $directory = storage_path('app/radiografias');
        File::ensureDirectoryExists($directory);
        $outputPath = $directory . '/radiografia_' . ($period->code ?: $period->id) . '.xlsx';

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($outputPath);

        return $outputPath;
    }

    private function fillSheet($spreadsheet, string $sheetName, array $map, array $metrics): void
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

    private function resolveTemplatePath(): string
    {
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
