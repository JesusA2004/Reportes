<?php

namespace App\Services;

use App\Enums\DataSourceCode;
use App\Enums\ProcessRunStatus;
use App\Enums\ProcessType;
use App\Enums\ReportUploadStatus;
use App\Models\ProcessRun;
use App\Models\ReportUpload;
use App\Services\Imports\NoiNominaImportService;
use Illuminate\Support\Facades\DB;

class ReportAnalysisService {

    public function __construct(
        protected NoiNominaImportService $noiNominaImportService,
    ) {
    }

    public function analyze(ReportUpload $upload): ProcessRun {
        $sourceCode = $upload->dataSource?->code;
        if (!$sourceCode) {
            throw new \RuntimeException('El archivo no tiene una fuente de datos asociada.');
        }
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
            $result = DB::transaction(function () use ($upload, $sourceCode) {
                return match ($sourceCode) {
                    DataSourceCode::NoiNomina->value => $this->noiNominaImportService->handle($upload),
                    default => throw new \RuntimeException("La fuente [{$sourceCode}] aún no tiene importador implementado."),
                };
            });
            $run->update([
                'rows_read' => (int) ($result['rows_read'] ?? 0),
                'rows_inserted' => (int) ($result['rows_inserted'] ?? 0),
                'rows_skipped' => (int) ($result['rows_skipped'] ?? 0),
                'rows_with_errors' => (int) ($result['rows_with_errors'] ?? 0),
                'status' => ProcessRunStatus::Success,
                'finished_at' => now(),
                'log' => (string) ($result['log'] ?? 'Análisis finalizado correctamente.'),
            ]);
            $upload->update([
                'status' => ReportUploadStatus::Processed,
            ]);
        } catch (\Throwable $exception) {
            $run->update([
                'status' => ProcessRunStatus::Failed,
                'finished_at' => now(),
                'log' => mb_strimwidth($exception->getMessage(), 0, 2000),
            ]);
            $upload->update([
                'status' => ReportUploadStatus::Failed,
            ]);
            throw $exception;
        }
        return $run->fresh();
    }

}
