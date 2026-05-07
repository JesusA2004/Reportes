<?php

namespace App\Jobs;

use App\Mail\ReportGeneratedMail;
use App\Mail\ReportGenerationFailedMail;
use App\Models\Period;
use App\Models\PeriodRadiographyExport;
use App\Models\PeriodRadiographyRun;
use App\Models\PeriodSummary;
use App\Models\User;
use App\Services\PeriodConsolidationService;
use App\Services\PeriodRadiographyService;
use App\Services\RadiografiaExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class GenerateRadiographyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;
    public int $tries = 3;
    public int $maxExceptions = 1;

    public function __construct(
        public int $periodId,
        public ?int $userId = null,
        public ?int $runId = null,
        public array $config = [],
    ) {
    }

    public function handle(
        PeriodRadiographyService $radiographyService,
        RadiografiaExportService $exportService,
        PeriodConsolidationService $consolidationService,
    ): void {
        @ini_set('memory_limit', '1024M');
        @ini_set('max_execution_time', '1800');
        @set_time_limit(1800);

        $period = Period::query()->findOrFail($this->periodId);

        $run = $this->runId
            ? PeriodRadiographyRun::query()->find($this->runId)
            : null;

        if (!$run) {
            $run = PeriodRadiographyRun::query()->create([
                'period_id'  => $period->id,
                'status'     => 'queued',
                'started_at' => now(),
                'created_by' => $this->userId,
                'log'        => 'Radiografía en cola.',
            ]);
        }

        $run->update([
            'status'     => 'running',
            'started_at' => $run->started_at ?: now(),
            'finished_at' => null,
            'log'        => 'Analizando fuentes y calculando métricas.',
        ]);

        try {
            // ── 1. Generate summary (metrics from Expense/Recovery/Placement/Portfolio) ──
            $summary = $radiographyService->generate($period, $this->userId);

            $run->update([
                'status'            => 'running',
                'period_summary_id' => $summary->id,
                'log'               => 'Consolidando empleados y nómina.',
            ]);

            // ── 2. Consolidate employee summaries (populates fact_monthly_employee_summary) ──
            $consolidationService->consolidate($period);

            $run->update([
                'log' => 'Generando Excel.',
            ]);

            // ── 3. Export Excel (from scratch, no template) ──
            $path = $exportService->export($period);

            $run->update([
                'log' => 'Generando PDF.',
            ]);

            // ── 4. Export PDF (via Blade + dompdf) ──
            $pdfPath = $exportService->exportPdf($period);

            // ── 5. Reload summary and register exports ──
            $summary = PeriodSummary::query()
                ->where('period_id', $period->id)
                ->first();

            if ($summary) {
                // Remove previous exports for this summary before creating new ones
                PeriodRadiographyExport::query()
                    ->where('period_summary_id', $summary->id)
                    ->delete();

                PeriodRadiographyExport::query()->create([
                    'period_summary_id' => $summary->id,
                    'export_path'       => $path,
                    'file_type'         => 'excel',
                    'template_version'  => config('app.version'),
                    'metadata'          => ['period_id' => $period->id, 'period_label' => $period->label, 'config' => $this->config],
                    'exported_at'       => now(),
                    'exported_by'       => $this->userId,
                ]);

                PeriodRadiographyExport::query()->create([
                    'period_summary_id' => $summary->id,
                    'export_path'       => $pdfPath,
                    'file_type'         => 'pdf',
                    'template_version'  => config('app.version'),
                    'metadata'          => ['period_id' => $period->id, 'period_label' => $period->label, 'config' => $this->config],
                    'exported_at'       => now(),
                    'exported_by'       => $this->userId,
                ]);
            }

            $run->update([
                'status'            => 'success',
                'period_summary_id' => $summary?->id,
                'finished_at'       => now(),
                'log'               => 'Radiografía generada. Excel y PDF listos para descargar.',
            ]);

            $this->notifyUser(
                subject: 'Radiografía lista',
                message: "La Radiografía del periodo {$period->label} ya está lista. Puedes consultarla en Reportes mensuales.",
                period: $period,
                success: true,
                run: $run,
            );
        } catch (\Throwable $exception) {
            Log::error('GenerateRadiographyJob falló.', [
                'period_id'  => $period->id,
                'run_id'     => $run->id,
                'exception'  => get_class($exception),
                'message'    => $exception->getMessage(),
                'file'       => $exception->getFile(),
                'line'       => $exception->getLine(),
                'trace'      => $exception->getTraceAsString(),
            ]);

            $publicError = $this->publicErrorMessage($exception);

            $run->update([
                'status'      => 'failed',
                'finished_at' => now(),
                'log'         => $publicError,
            ]);

            $this->notifyUser(
                subject: 'Error al generar Radiografía',
                message: $publicError,
                period: $period,
                run: $run,
                success: false,
            );

            throw $exception;
        }
    }

    private function notifyUser(string $subject, string $message, Period $period, bool $success, ?PeriodRadiographyRun $run = null): void
    {
        if (!$this->userId) {
            return;
        }

        $user = User::query()->find($this->userId);

        if (!$user || !$user->email) {
            return;
        }

        $run ??= $this->runId
            ? PeriodRadiographyRun::query()->find($this->runId)
            : PeriodRadiographyRun::query()->where('period_id', $period->id)->latest('id')->first();

        try {
            $downloadUrl = route('reportes-mensuales.preview', $period->id);

            if (!$success) {
                Mail::to($user->email)->send(
                    new ReportGenerationFailedMail($period, $user, $run, $message)
                );
            } else {
                Mail::to($user->email)->send(
                    new ReportGeneratedMail($period, $user, $run, $downloadUrl)
                );
            }
        } catch (\Throwable $exception) {
            Log::warning('No se pudo enviar correo de Radiografía.', [
                'user_id'   => $this->userId,
                'period_id' => $period->id,
                'error'     => $exception->getMessage(),
            ]);
        }
    }

    private function publicErrorMessage(\Throwable $exception): string
    {
        $msg = $exception->getMessage();

        if (
            str_contains($msg, 'PhpOffice') ||
            str_contains($msg, 'PhpSpreadsheet') ||
            str_contains($msg, 'Call to undefined method') ||
            str_contains($msg, 'Spreadsheet') ||
            str_contains(get_class($exception), 'PhpOffice')
        ) {
            return 'No se pudo preparar el archivo Excel del reporte. Revisa la configuración del formato e inténtalo nuevamente.';
        }

        if (
            str_contains($msg, 'SQLSTATE') ||
            str_contains($msg, 'QueryException') ||
            str_contains(get_class($exception), 'QueryException')
        ) {
            return 'No se pudo consultar la información del reporte. Verifica que los datos del periodo estén procesados correctamente.';
        }

        if (
            str_contains($msg, 'Dompdf') ||
            str_contains($msg, 'dompdf') ||
            str_contains(get_class($exception), 'Dompdf')
        ) {
            return 'No se pudo generar el PDF del reporte. Inténtalo nuevamente o descarga el Excel si está disponible.';
        }

        if (
            str_contains($msg, 'Faltan fuentes') ||
            str_contains($msg, 'No se puede generar la Radiografía')
        ) {
            return $msg;
        }

        if (str_contains($msg, 'no quedó procesada') || str_contains($msg, 'no tiene ruta de almacenamiento')) {
            return $msg;
        }

        return 'No se pudo generar la Radiografía. Revisa las fuentes cargadas, incidencias y configuración del reporte.';
    }
}
