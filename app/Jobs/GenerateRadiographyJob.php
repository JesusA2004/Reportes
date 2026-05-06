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
            );
        } catch (\Throwable $exception) {
            $run->update([
                'status'     => 'failed',
                'finished_at' => now(),
                'log'        => mb_strimwidth($exception->getMessage(), 0, 2000),
            ]);

            $this->notifyUser(
                subject: 'Error al generar Radiografía',
                message: "No se pudo generar la Radiografía del periodo {$period->label}. Error: " . mb_strimwidth($exception->getMessage(), 0, 500),
                period: $period,
                success: false,
            );

            throw $exception;
        }
    }

    private function notifyUser(string $subject, string $message, Period $period, bool $success): void
    {
        if (!$this->userId) {
            return;
        }

        $user = User::query()->find($this->userId);

        if (!$user || !$user->email) {
            return;
        }

        $run = $this->runId
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
}
