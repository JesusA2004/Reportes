<?php

namespace App\Services;

use App\Models\MonthlyEmployeeSummary;
use App\Models\Period;
use App\Models\PeriodBranchSummary;
use App\Models\PeriodSummary;
use App\Services\Radiography\RadiographyWorkbookBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\IOFactory;

class RadiografiaExportService
{
    public function __construct(
        private RadiographyWorkbookBuilder $workbookBuilder,
    ) {}

    public function export(Period $period): string
    {
        @ini_set('memory_limit', '1024M');

        $summary = PeriodSummary::query()
            ->with(['branchSummaries'])
            ->where('period_id', $period->id)
            ->where('status', 'generated')
            ->first();

        if (!$summary) {
            throw new \RuntimeException("No existe una radiografía generada para el periodo {$period->label}.");
        }

        $spreadsheet = $this->workbookBuilder->build($period, $summary);

        $directory = storage_path('app/radiografias');
        File::ensureDirectoryExists($directory);
        $outputPath = $directory . '/radiografia_' . ($period->code ?: $period->id) . '.xlsx';

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->setPreCalculateFormulas(false);
        $writer->save($outputPath);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $outputPath;
    }

    public function exportPdf(Period $period): string
    {
        @ini_set('memory_limit', '1024M');

        $summary = PeriodSummary::query()
            ->with(['branchSummaries', 'incidents'])
            ->where('period_id', $period->id)
            ->where('status', 'generated')
            ->first();

        if (!$summary) {
            throw new \RuntimeException("No existe una radiografía generada para el periodo {$period->label}.");
        }

        $gm = $summary->global_metrics ?? [];

        $emp = MonthlyEmployeeSummary::query()
            ->where('period_id', $period->id)
            ->selectRaw('COUNT(*) as total_empleados, SUM(total_payments) as pagos, SUM(total_bonuses) as bonos, SUM(total_discounts) as descuentos, SUM(total_expenses) as gastos, SUM(net_amount) as neto')
            ->first();

        $employees = MonthlyEmployeeSummary::query()
            ->with(['employee:id,full_name', 'branch:id,name'])
            ->where('period_id', $period->id)
            ->orderByDesc('total_payments')
            ->limit(30)
            ->get()
            ->map(fn ($row) => [
                'name'     => $row->employee?->full_name ?? 'Sin empleado',
                'branch'   => $row->branch?->name,
                'pagos'    => (float)$row->total_payments,
                'gastos'   => (float)$row->total_expenses,
                'neto'     => (float)$row->net_amount,
                'included' => (bool)$row->included_in_report,
            ])
            ->toArray();

        $branches = $summary->branchSummaries->map(function (PeriodBranchSummary $bs) {
            $m = $bs->metrics ?? [];
            $branch = \App\Models\Branch::query()->find($bs->branch_id);
            return [
                'name'         => $branch?->name ?? "Sucursal #{$bs->branch_id}",
                'recuperacion' => (float)($m['recuperacion_total'] ?? 0),
                'colocacion'   => (float)($m['colocacion_total'] ?? 0),
                'mora'         => (float)($m['mora_porcentaje'] ?? 0),
                'gastos'       => (float)($m['gasto_total'] ?? 0),
            ];
        })->toArray();

        $incidents = $summary->incidents->map(fn ($i) => [
            'type'     => $i->type,
            'severity' => $i->severity,
            'message'  => $i->message,
        ])->toArray();

        $pdf = Pdf::loadView('reports.radiography-pdf', [
            'period'    => $period,
            'metrics'   => $gm,
            'payroll'   => [
                'total_empleados' => (int)($emp?->total_empleados ?? 0),
                'pagos'           => (float)($emp?->pagos ?? 0),
                'bonos'           => (float)($emp?->bonos ?? 0),
                'descuentos'      => (float)($emp?->descuentos ?? 0),
                'gastos'          => (float)($emp?->gastos ?? 0),
                'neto'            => (float)($emp?->neto ?? 0),
            ],
            'employees' => $employees,
            'branches'  => $branches,
            'incidents' => $incidents,
        ])->setPaper('letter', 'portrait');

        $directory  = storage_path('app/radiografias');
        File::ensureDirectoryExists($directory);
        $outputPath = $directory . '/radiografia_' . ($period->code ?: $period->id) . '.pdf';

        $pdf->save($outputPath);

        return $outputPath;
    }
}
