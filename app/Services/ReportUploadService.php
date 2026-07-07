<?php

namespace App\Services;

use App\Enums\ReportUploadStatus;
use App\Models\ReportUpload;
use App\Models\Period;
use App\Services\PeriodDerivedDataCleaner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

class ReportUploadService
{
    public function __construct(
        protected PeriodRadiographyService $periodRadiographyService,
        protected PeriodDerivedDataCleaner $cleaner,
    ) {}

    public function store(
        int $periodId,
        array $coveredPeriodIds,
        int $dataSourceId,
        UploadedFile $file,
        ?string $notes = null,
    ): ReportUpload {
        $storedPath = $file->store('reports', 'public');

        // Capture existing uploads BEFORE creating the new one so we can clean them up.
        $existingUploads = ReportUpload::query()
            ->where('period_id', $periodId)
            ->where('data_source_id', $dataSourceId)
            ->get();

        $upload = ReportUpload::create([
            'period_id'          => $periodId,
            'covered_period_ids' => array_values(array_unique(array_map('intval', $coveredPeriodIds))),
            'data_source_id'     => $dataSourceId,
            'original_name'      => $file->getClientOriginalName(),
            'stored_path'        => $storedPath,
            'mime_type'          => $file->getClientMimeType(),
            'file_size'          => $file->getSize(),
            'uploaded_by'        => Auth::id(),
            'uploaded_at'        => now(),
            'status'             => ReportUploadStatus::Pending,
            'notes'              => $notes,
        ]);

        if ($existingUploads->isNotEmpty()) {
            $period = Period::query()->find($periodId);
            if ($period) {
                // Invalidate the period summary — this makes Etapa 2 show as "pendiente de volver a cargar"
                // and prevents a stale 'database_updated' summary from unlocking generation with no data.
                $this->periodRadiographyService->invalidateForPeriod(
                    $period,
                    Auth::id(),
                    "Se reemplazó el archivo '{$file->getClientOriginalName()}' después de la última carga. Vuelve a ejecutar Cargar registros."
                );

                // Clear fact data for every old upload being replaced, and mark it as
                // Replaced so it's never picked as the "vigente" upload nor reprocessed.
                foreach ($existingUploads as $old) {
                    $this->cleaner->clearForUpload($old);
                    $old->update(['status' => ReportUploadStatus::Replaced]);
                }
            }
        }

        return $upload;
    }
}
