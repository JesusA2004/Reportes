<?php

namespace App\Services;

use App\Enums\DataSourceCode;
use App\Enums\ProcessRunStatus;
use App\Enums\ProcessType;
use App\Enums\ReportUploadStatus;
use App\Models\ProcessRun;
use App\Models\ReportUpload;
use App\Services\Imports\GastosImportService;
use App\Services\Imports\LendusIngresosCobranzaImportService;
use App\Services\Imports\NoiNominaImportService;
use App\Services\Imports\LendusMinistracionesImportService;
use App\Services\Imports\LendusSaldosClienteImportService;
use Illuminate\Support\Facades\DB;

class ReportAnalysisService
{
    public function __construct(
        protected NoiNominaImportService $noiNominaImportService,
        protected GastosImportService $gastosImportService,
        protected LendusIngresosCobranzaImportService $lendusIngresosCobranzaImportService,
        protected LendusMinistracionesImportService $lendusMinistracionesImportService,
        protected LendusSaldosClienteImportService $lendusSaldosClienteImportService,
    ) {
    }

    public function analyze(ReportUpload $upload): ProcessRun
    {
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
            $result = DB::transaction(function () use ($upload, $sourceCode, $run) {
                $progress = function (array $stats) use ($run) {
                    $run->update([
                        'rows_read' => (int) ($stats['rows_read'] ?? $run->rows_read),
                        'rows_inserted' => (int) ($stats['rows_inserted'] ?? $run->rows_inserted),
                        'rows_skipped' => (int) ($stats['rows_skipped'] ?? $run->rows_skipped),
                        'rows_with_errors' => (int) ($stats['rows_with_errors'] ?? $run->rows_with_errors),
                        'log' => (string) ($stats['log'] ?? $run->log),
                    ]);
                };
                return match ($sourceCode) {
                    DataSourceCode::NoiNomina->value => $this->noiNominaImportService->handle($upload, $progress),
                    DataSourceCode::Gastos->value => $this->gastosImportService->handle($upload, $progress),
                    DataSourceCode::LendusIngresosCobranza->value => $this->lendusIngresosCobranzaImportService->handle($upload, $progress),
                    DataSourceCode::LendusMinistraciones->value => $this->lendusMinistracionesImportService->handle($upload, $progress),
                    DataSourceCode::LendusSaldosCliente->value => $this->lendusSaldosClienteImportService->handle($upload, $progress),
                    default => throw new \RuntimeException("La fuente [{$sourceCode}] aún no tiene importador implementado."),
                };
            });
            $rowsRead = (int) ($result['rows_read'] ?? 0);
            $rowsInserted = (int) ($result['rows_inserted'] ?? 0);
            $rowsSkipped = (int) ($result['rows_skipped'] ?? 0);
            $rowsWithErrors = (int) ($result['rows_with_errors'] ?? 0);
            $log = (string) ($result['log'] ?? 'Análisis finalizado.');
            $successStatus = $rowsInserted > 0
                ? ReportUploadStatus::Processed
                : ReportUploadStatus::Pending;
            $successLog = $rowsInserted > 0
                ? $log
                : 'El archivo fue analizado, pero no generó registros útiles. Revisa Asignación sucursal, incidencias y el mapeo de columnas.';
            $run->update([
                'rows_read' => $rowsRead,
                'rows_inserted' => $rowsInserted,
                'rows_skipped' => $rowsSkipped,
                'rows_with_errors' => $rowsWithErrors,
                'status' => ProcessRunStatus::Success,
                'finished_at' => now(),
                'log' => $successLog,
            ]);
            $upload->update([
                'status' => $successStatus,
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
