<?php

namespace App\Services;

use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\Radiography\RadiographySnapshotBuilder;
use App\Services\Radiography\RadiographyWorkbookBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class RadiografiaExportService
{
    public function __construct(
        private RadiographyWorkbookBuilder $workbookBuilder,
        private RadiographySnapshotBuilder $snapshotBuilder,
    ) {}

    public function export(Period $period, array $config = []): string
    {
        @ini_set('memory_limit', '1024M');

        $summary = $this->requireSummary($period);

        $snapshot    = $this->snapshotBuilder->build($period, $summary, $config);
        $spreadsheet = $this->workbookBuilder->buildFromSnapshot($period, $summary, $snapshot);

        $directory  = storage_path('app/radiografias');
        File::ensureDirectoryExists($directory);
        $outputPath = $directory . '/radiografia_' . ($period->code ?: $period->id) . '_' . now()->format('Ymd_His') . '.xlsx';

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->setPreCalculateFormulas(false);
        $writer->setIncludeCharts(true);
        $writer->save($outputPath);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $outputPath;
    }

    public function exportPdf(Period $period, array $config = []): string
    {
        @ini_set('memory_limit', '1024M');

        $summary = $this->requireSummary($period);
        $summary->loadMissing(['branchSummaries', 'incidents']);

        $snapshot = $this->snapshotBuilder->build($period, $summary, $config);

        $pdf = Pdf::loadView('reports.radiography-pdf', [
            'period'   => $period,
            'snapshot' => $snapshot,
        ])->setPaper('letter', 'portrait')->setOption('isPhpEnabled', true);

        $directory  = storage_path('app/radiografias');
        File::ensureDirectoryExists($directory);
        $outputPath = $directory . '/radiografia_' . ($period->code ?: $period->id) . '_' . now()->format('Ymd_His') . '.pdf';

        $pdf->save($outputPath);

        return $outputPath;
    }

    /**
     * Export a filtered/comparative workbook based on config:
     *   scope: general | branch | employee
     *   report_type: simple | month_vs_month | bimester_vs_bimester | quarter_vs_quarter
     *   branch_id, employee_id, compare_period_id,
     *   extra_employee_expense_amount, extra_employee_expense_notes
     */
    public function exportWithConfig(Period $period, array $config): string
    {
        @ini_set('memory_limit', '1024M');

        $summary  = $this->requireSummary($period);
        $snapshot = $this->snapshotBuilder->build($period, $summary);

        $scope      = $config['scope'] ?? 'general';
        $reportType = $config['report_type'] ?? 'simple';

        if (in_array($reportType, ['month_vs_month', 'bimester_vs_bimester', 'quarter_vs_quarter'])) {
            $comparePeriodId = (int) ($config['compare_period_id'] ?? 0);
            if (!$comparePeriodId) {
                throw new RuntimeException('Se requiere compare_period_id para reportes comparativos.');
            }
            $comparePeriod = Period::findOrFail($comparePeriodId);
            $compareSummary = $this->requireSummary($comparePeriod);
            $compareSnap    = $this->snapshotBuilder->build($comparePeriod, $compareSummary);
            $spreadsheet    = $this->workbookBuilder->buildComparativeFromSnapshots($period, $snapshot, $comparePeriod, $compareSnap, $config);
            $suffix         = 'comparativo_' . $comparePeriod->code . '_vs_' . ($period->code ?: $period->id);
        } elseif ($scope === 'branch') {
            $branchId = (int) ($config['branch_id'] ?? 0);
            if (!$branchId) {
                throw new RuntimeException('Se requiere branch_id para reportes por sucursal.');
            }
            $spreadsheet = $this->workbookBuilder->buildBranchFromSnapshot($period, $summary, $snapshot, $branchId);
            $suffix      = 'sucursal_' . $branchId;
        } elseif ($scope === 'employee') {
            $employeeId    = (int) ($config['employee_id'] ?? 0);
            if (!$employeeId) {
                throw new RuntimeException('Se requiere employee_id para reportes por gestor.');
            }
            $extraAmount = (float) ($config['extra_employee_expense_amount'] ?? 0);
            $extraNotes  = (string) ($config['extra_employee_expense_notes'] ?? '');
            $spreadsheet = $this->workbookBuilder->buildEmployeeFromSnapshot($period, $summary, $snapshot, $employeeId, $extraAmount, $extraNotes);
            $suffix      = 'gestor_' . $employeeId;
        } else {
            $spreadsheet = $this->workbookBuilder->buildFromSnapshot($period, $summary, $snapshot);
            $suffix      = 'general';
        }

        $directory  = storage_path('app/radiografias');
        File::ensureDirectoryExists($directory);
        $outputPath = $directory . '/radiografia_' . ($period->code ?: $period->id) . '_' . $suffix . '_' . now()->format('Ymd_His') . '.xlsx';

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->setPreCalculateFormulas(false);
        $writer->setIncludeCharts(true);
        $writer->save($outputPath);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $outputPath;
    }

    public function exportPdfWithConfig(Period $period, array $config): string
    {
        @ini_set('memory_limit', '1024M');

        $summary = $this->requireSummary($period);
        $summary->loadMissing(['branchSummaries', 'incidents']);
        $snapshot = $this->snapshotBuilder->build($period, $summary);

        $scope      = $config['scope'] ?? 'general';
        $reportType = $config['report_type'] ?? 'simple';

        $pdf = Pdf::loadView('reports.radiography-pdf', [
            'period'      => $period,
            'snapshot'    => $snapshot,
            'config'      => $config,
            'scope'       => $scope,
            'report_type' => $reportType,
        ])->setPaper('letter', 'portrait')->setOption('isPhpEnabled', true);

        $suffix = match(true) {
            in_array($reportType, ['month_vs_month', 'bimester_vs_bimester', 'quarter_vs_quarter'])
                => 'comparativo_' . ($config['compare_period_id'] ?? 'unknown'),
            $scope === 'branch'   => 'sucursal_' . ($config['branch_id'] ?? 'unknown'),
            $scope === 'employee' => 'gestor_' . ($config['employee_id'] ?? 'unknown'),
            default               => 'general',
        };

        $directory  = storage_path('app/radiografias');
        File::ensureDirectoryExists($directory);
        $outputPath = $directory . '/radiografia_' . ($period->code ?: $period->id) . '_' . $suffix . '_' . now()->format('Ymd_His') . '.pdf';

        $pdf->save($outputPath);

        return $outputPath;
    }

    /**
     * Build the snapshot for use in the web preview page.
     * This avoids recalculating in the controller.
     */
    public function buildSnapshot(Period $period, array $config = []): array
    {
        $summary = $this->requireSummary($period);
        $summary->loadMissing(['branchSummaries', 'incidents']);
        return $this->snapshotBuilder->build($period, $summary, $config);
    }

    private function requireSummary(Period $period): PeriodSummary
    {
        $summary = PeriodSummary::query()
            ->with(['branchSummaries', 'incidents'])
            ->where('period_id', $period->id)
            ->where('status', 'generated')
            ->first();

        if (!$summary) {
            throw new \RuntimeException("No existe una radiografía generada para el periodo {$period->label}.");
        }

        return $summary;
    }
}
