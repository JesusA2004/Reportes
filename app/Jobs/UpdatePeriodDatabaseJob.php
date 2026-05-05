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

    public int $timeout = 900;
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
        @ini_set('max_execution_time', '900');
        @set_time_limit(900);

        $period = Period::query()->findOrFail($this->periodId);
        $run    = PeriodDatabaseUpdateRun::query()->findOrFail($this->runId);

        $run->update([
            'status'     => 'running',
            'started_at' => $run->started_at ?? now(),
            'log'        => 'Procesando NOI Nómina y Cobranza…',
        ]);

        try {
            $service->updateForPeriod($period);

            $warnings = $period->fresh()
                ?->periodSummary
                ?->warnings['db_update'] ?? [];

            $run->update([
                'status'      => 'success',
                'finished_at' => now(),
                'log'         => 'Base de datos actualizada correctamente.',
            ]);

            $this->notifyUser('success', $period, $run, $warnings);
        } catch (\Throwable $exception) {
            $errorMsg = mb_strimwidth($exception->getMessage(), 0, 2000);

            $run->update([
                'status'        => 'failed',
                'finished_at'   => now(),
                'log'           => 'El proceso terminó con error.',
                'error_message' => $errorMsg,
            ]);

            $this->notifyUser('failed', $period, $run, [], $errorMsg);

            throw $exception;
        }
    }

    private function notifyUser(
        string $result,
        Period $period,
        PeriodDatabaseUpdateRun $run,
        array $stats = [],
        string $errorMessage = '',
    ): void {
        if (!$this->userId) {
            return;
        }

        $user = User::query()->find($this->userId);

        if (!$user || !$user->email) {
            return;
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
        } catch (\Throwable $mailException) {
            Log::warning('No se pudo enviar correo de actualización BD.', [
                'user_id'   => $this->userId,
                'period_id' => $this->periodId,
                'result'    => $result,
                'error'     => $mailException->getMessage(),
            ]);
        }
    }
}
