<?php

namespace App\Services;

use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\Radiography\RadiographySnapshotBuilder;
use App\Services\Radiography\RadiographyWorkbookBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\IOFactory;

class RadiografiaExportService
{
    public function __construct(
        private RadiographyWorkbookBuilder $workbookBuilder,
        private RadiographySnapshotBuilder $snapshotBuilder,
    ) {}

    public function export(Period $period): string
    {
        @ini_set('memory_limit', '1024M');

        $summary = $this->requireSummary($period);

        $snapshot    = $this->snapshotBuilder->build($period, $summary);
        $spreadsheet = $this->workbookBuilder->buildFromSnapshot($period, $summary, $snapshot);

        $directory  = storage_path('app/radiografias');
        File::ensureDirectoryExists($directory);
        $outputPath = $directory . '/radiografia_' . ($period->code ?: $period->id) . '_' . now()->format('Ymd_His') . '.xlsx';

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

        $summary = $this->requireSummary($period);
        $summary->loadMissing(['branchSummaries', 'incidents']);

        $snapshot = $this->snapshotBuilder->build($period, $summary);

        $pdf = Pdf::loadView('reports.radiography-pdf', [
            'period'   => $period,
            'snapshot' => $snapshot,
        ])->setPaper('letter', 'portrait');

        $directory  = storage_path('app/radiografias');
        File::ensureDirectoryExists($directory);
        $outputPath = $directory . '/radiografia_' . ($period->code ?: $period->id) . '_' . now()->format('Ymd_His') . '.pdf';

        $pdf->save($outputPath);

        return $outputPath;
    }

    /**
     * Build the snapshot for use in the web preview page.
     * This avoids recalculating in the controller.
     */
    public function buildSnapshot(Period $period): array
    {
        $summary = $this->requireSummary($period);
        $summary->loadMissing(['branchSummaries', 'incidents']);
        return $this->snapshotBuilder->build($period, $summary);
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
