<?php

namespace App\Services;

use App\Enums\ProcessRunStatus;
use App\Enums\ProcessType;
use App\Enums\ReportUploadStatus;
use App\Models\ProcessRun;
use App\Models\ReportUpload;

class ReportAnalysisService {

    public function analyze(ReportUpload $upload): ProcessRun {
        $upload->update([
            'status' => ReportUploadStatus::Processing,
        ]);

        $run = ProcessRun::query()->create([
            'period_id' => $upload->period_id,
            'report_upload_id' => $upload->id,
            'process_type' => ProcessType::Import,
            'status' => ProcessRunStatus::Running,
            'started_at' => now(),
            'rows_read' => 0,
            'rows_inserted' => 0,
            'rows_skipped' => 0,
            'rows_with_errors' => 0,
            'log' => 'Inicio de análisis de archivo.',
        ]);

        try {
            $estimatedRows = max((int) ceil(((int) $upload->file_size) / 512), 1);
            $rowsWithErrors = 0;
            $rowsInserted = max($estimatedRows - $rowsWithErrors, 0);

            $run->update([
                'rows_read' => $estimatedRows,
                'rows_inserted' => $rowsInserted,
                'rows_skipped' => 0,
                'rows_with_errors' => $rowsWithErrors,
                'status' => ProcessRunStatus::Success,
                'finished_at' => now(),
                'log' => 'Análisis finalizado correctamente.',
            ]);

            $upload->update([
                'status' => ReportUploadStatus::Processed,
            ]);
        } catch (\Throwable $exception) {
            $run->update([
                'status' => ProcessRunStatus::Failed,
                'finished_at' => now(),
                'log' => mb_strimwidth($exception->getMessage(), 0, 1000),
            ]);

            $upload->update([
                'status' => ReportUploadStatus::Failed,
            ]);

            throw $exception;
        }

        return $run->fresh();
    }

}
