<?php

namespace App\Services;

use App\Enums\ReportUploadStatus;
use App\Models\ReportUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

class ReportUploadService
{
    public function store(
        int $periodId,
        array $coveredPeriodIds,
        int $dataSourceId,
        UploadedFile $file,
        ?string $notes = null,
    ): ReportUpload {
        $storedPath = $file->store('reports', 'public');

        return ReportUpload::create([
            'period_id' => $periodId,
            'covered_period_ids' => array_values(array_unique(array_map('intval', $coveredPeriodIds))),
            'data_source_id' => $dataSourceId,
            'original_name' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'uploaded_by' => Auth::id(),
            'uploaded_at' => now(),
            'status' => ReportUploadStatus::Pending,
            'notes' => $notes,
        ]);
    }
}
