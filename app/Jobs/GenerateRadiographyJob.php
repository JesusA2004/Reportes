<?php

namespace App\Jobs;

use App\Models\Period;
use App\Models\PeriodRadiographyExport;
use App\Models\PeriodRadiographyRun;
use App\Models\PeriodSummary;
use App\Models\User;
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
    ) {
    }

    public function handle(
        PeriodRadiographyService $radiographyService,
        RadiografiaExportService $exportService,
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
                'period_id' => $period->id,
                'status' => 'queued',
                'started_at' => now(),
                'created_by' => $this->userId,
                'log' => 'Radiografía en cola.',
            ]);
        }

        $run->update([
            'status' => 'running',
            'started_at' => $run->started_at ?: now(),
            'finished_at' => null,
            'log' => 'Analizando fuentes pendientes.',
        ]);

        try {
            $summary = $radiographyService->generate($period, $this->userId);

            $run->update([
                'status' => 'running',
                'period_summary_id' => $summary->id,
                'log' => 'Generando Excel final.',
            ]);

            $path = $exportService->export($period);

            $summary = PeriodSummary::query()
                ->where('period_id', $period->id)
                ->first();

            if ($summary) {
                PeriodRadiographyExport::query()->create([
                    'period_summary_id' => $summary->id,
                    'export_path' => $path,
                    'template_version' => config('app.version'),
                    'exported_at' => now(),
                    'exported_by' => $this->userId,
                ]);
            }

            $run->update([
                'status' => 'success',
                'period_summary_id' => $summary?->id,
                'finished_at' => now(),
                'log' => 'Radiografía generada y Excel final listo para descargar.',
            ]);

            $this->notifyUser(
                subject: 'Radiografía lista para descargar',
                message: "La Radiografía del periodo {$period->label} ya está lista para descargar.",
                period: $period,
            );
        } catch (\Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'log' => mb_strimwidth($exception->getMessage(), 0, 2000),
            ]);

            $this->notifyUser(
                subject: 'Error al generar Radiografía',
                message: "No se pudo generar la Radiografía del periodo {$period->label}. Error: "
                    . mb_strimwidth($exception->getMessage(), 0, 1000),
                period: $period,
            );

            throw $exception;
        }
    }

    private function notifyUser(string $subject, string $message, Period $period): void
    {
        if (!$this->userId) {
            return;
        }

        $user = User::query()->find($this->userId);

        if (!$user || !$user->email) {
            return;
        }

        try {
            $downloadUrl = route('reportes-mensuales.export-radiography', $period);

            Mail::raw($message . PHP_EOL . PHP_EOL . 'Descargar: ' . $downloadUrl, function ($mail) use ($user, $subject) {
                $mail->to($user->email)->subject($subject);
            });
        } catch (\Throwable $exception) {
            Log::warning('No se pudo enviar correo de Radiografía.', [
                'user_id' => $this->userId,
                'period_id' => $period->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
