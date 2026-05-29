<?php

namespace App\Jobs;

use App\Mail\DatabaseUpdateCompletedMail;
use App\Mail\DatabaseUpdateFailedMail;
use App\Models\Period;
use App\Models\PeriodDatabaseUpdateRun;
use App\Models\User;
use App\Services\DatabaseUpdateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class UpdatePeriodDatabaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;
    public int $maxExceptions = 1;

    public function __construct(
        public int $periodId,
        public int $runId,
        public ?int $userId = null,
    ) {}

    public function handle(DatabaseUpdateService $service): void
    {
        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', '1800');
        @set_time_limit(1800);

        $period = Period::query()->findOrFail($this->periodId);
        $run    = PeriodDatabaseUpdateRun::query()->findOrFail($this->runId);

        // If already cancelled before the worker picked it up, abort silently
        if (!in_array($run->status, ['queued', 'running'], true)) {
            Log::info('UpdatePeriodDatabaseJob: run ya no está activo al iniciar.', ['run_id' => $this->runId, 'status' => $run->status]);
            return;
        }

        $run->update([
            'status'     => 'running',
            'started_at' => now(),
            'log'        => 'Procesando NOI Nómina y Cobranza…',
            'metadata'   => ['current_step' => 'Iniciando proceso…', 'progress_percent' => 0],
        ]);

        try {
            $service->updateForPeriod($period, $run);

            // Re-check cancellation after service (in case it was cancelled during the last step)
            $run->refresh();
            if (!in_array($run->status, ['running'], true)) {
                Log::info('UpdatePeriodDatabaseJob: run cancelado durante procesamiento.', ['run_id' => $this->runId]);
                return;
            }

            $warnings = $period->fresh()?->periodSummary?->warnings['db_update'] ?? [];

            // Validate consistency before marking success: uploads must be processed
            // and email must only be sent when fact_* actually have data.
            $allPeriods  = \App\Models\Period::all();
            $dataIds     = array_values(array_unique(array_merge([$this->periodId],
                $period->resolveBaseWeeklyIds($allPeriods))));
            $hasNoi      = \Illuminate\Support\Facades\DB::table('fact_noi_movements')->whereIn('period_id', $dataIds)->exists();
            $hasCobranza = \Illuminate\Support\Facades\DB::table('fact_recoveries')->whereIn('period_id', $dataIds)->exists();

            if (!$hasNoi || !$hasCobranza) {
                // Fact tables empty despite no exception — mark as failed
                $missing = array_filter([!$hasNoi ? 'NOI' : null, !$hasCobranza ? 'Cobranza' : null]);
                throw new \RuntimeException(
                    'El proceso terminó sin errores pero las tablas de hechos quedaron vacías (' . implode(', ', $missing) . '). '
                    . 'Verifica los archivos y vuelve a ejecutar Cargar registros.'
                );
            }

            $run->update([
                'status'      => 'success',
                'finished_at' => now(),
                'log'         => 'Base de datos actualizada correctamente. Todos los archivos importados y uploads procesados.',
            ]);

            $emailNote = $this->notifyUser('success', $period, $run, $warnings);
            $run->update(['log' => 'Base de datos actualizada correctamente. Todos los archivos importados y uploads procesados. ' . $emailNote]);
        } catch (\Throwable $exception) {
            // Don't overwrite a manual cancel
            $run->refresh();
            if (!in_array($run->status, ['running'], true)) {
                Log::info('UpdatePeriodDatabaseJob: run cancelado, omitiendo marca de error.', ['run_id' => $this->runId]);
                return;
            }

            $errorMsg = mb_strimwidth($exception->getMessage(), 0, 2000);

            $run->update([
                'status'        => 'failed',
                'finished_at'   => now(),
                'log'           => 'El proceso terminó con error.',
                'error_message' => $errorMsg,
            ]);

            $emailNote = $this->notifyUser('failed', $period, $run, [], $errorMsg);
            $run->update(['log' => 'El proceso terminó con error. ' . $emailNote]);

            throw $exception;
        }
    }

    private function notifyUser(
        string $result,
        Period $period,
        PeriodDatabaseUpdateRun $run,
        array $stats = [],
        string $errorMessage = '',
    ): string {
        if (!$this->userId) {
            return 'Sin usuario asignado — correo no enviado.';
        }

        $user = User::query()->find($this->userId);

        if (!$user || !$user->email) {
            Log::warning('UpdatePeriodDatabaseJob: usuario sin correo.', [
                'user_id'   => $this->userId,
                'period_id' => $this->periodId,
            ]);
            return 'Usuario sin correo configurado — notificación omitida.';
        }

        try {
            if ($result === 'success') {
                Mail::to($user->email)->send(
                    new DatabaseUpdateCompletedMail($period, $user, $run, $stats)
                );
            } else {
                Mail::to($user->email)->send(
                    new DatabaseUpdateFailedMail($period, $user, $run, $errorMessage)
                );
            }

            Log::info('UpdatePeriodDatabaseJob: correo enviado.', [
                'email'     => $user->email,
                'result'    => $result,
                'period_id' => $this->periodId,
            ]);

            return "Correo enviado a {$user->email}.";
        } catch (\Throwable $mailException) {
            Log::warning('UpdatePeriodDatabaseJob: no se pudo enviar correo.', [
                'user_id'   => $this->userId,
                'email'     => $user->email,
                'period_id' => $this->periodId,
                'result'    => $result,
                'error'     => $mailException->getMessage(),
            ]);
            return 'No se pudo enviar correo a ' . $user->email . ': ' . mb_strimwidth($mailException->getMessage(), 0, 200);
        }
    }
}
