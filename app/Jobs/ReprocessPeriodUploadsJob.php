<?php

namespace App\Jobs;

use App\Mail\ReprocessPeriodCompletedMail;
use App\Mail\ReprocessPeriodFailedMail;
use App\Models\Period;
use App\Models\PeriodReprocessRun;
use App\Models\ReportUpload;
use App\Models\User;
use App\Services\ReportAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ReprocessPeriodUploadsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;
    public int $maxExceptions = 1;

    // Progress milestone per source code after completing that source
    private const SOURCE_PROGRESS = [
        'noi_nomina'               => 20,
        'lendus_ingresos_cobranza' => 35,
        'lendus_ministraciones'    => 55,
        'lendus_saldos_cliente'    => 75,
        'gastos'                   => 90,
    ];

    public function __construct(
        public int $periodId,
        public int $runId,
        public array $coveredPeriodIds,
        public ?int $userId = null,
    ) {}

    public function handle(ReportAnalysisService $service): void
    {
        @ini_set('memory_limit', '1024M');
        @ini_set('max_execution_time', '3600');
        @set_time_limit(3600);

        $period = Period::query()->findOrFail($this->periodId);
        $run    = PeriodReprocessRun::query()->findOrFail($this->runId);
        $user   = $this->userId ? User::query()->find($this->userId) : null;

        $run->update(['status' => 'running', 'started_at' => now()]);

        try {
            $uploads = ReportUpload::query()
                ->with('dataSource')
                ->get()
                ->filter(fn (ReportUpload $u) => collect($u->covered_period_ids ?? [])
                    ->map(fn ($id) => (int) $id)
                    ->intersect($this->coveredPeriodIds)
                    ->isNotEmpty()
                )
                ->unique('id')
                ->values();

            $total    = $uploads->count();
            $metadata = ['progress' => 10, 'total' => $total, 'completed' => 0];

            $run->update([
                'log'      => "Iniciando reprocesamiento de {$total} fuente(s)...",
                'metadata' => $metadata,
            ]);

            // Sort by canonical source order so progress milestones make sense
            $sortOrder = array_keys(self::SOURCE_PROGRESS);
            $sorted = $uploads->sortBy(
                fn (ReportUpload $u) => ($pos = array_search($u->dataSource?->code ?? '', $sortOrder)) !== false ? $pos : 99
            )->values();

            $results  = [];
            $errors   = [];
            $completed = 0;

            foreach ($sorted as $upload) {
                $sourceCode = $upload->dataSource?->code ?? '';
                $sourceName = $upload->dataSource?->name ?? 'Fuente desconocida';

                $metadata['current_source'] = $sourceName;
                $run->update(['log' => "Procesando {$sourceName}...", 'metadata' => $metadata]);

                try {
                    $processRun = $service->analyze($upload);

                    $results[$sourceCode] = [
                        'ok'       => true,
                        'name'     => $sourceName,
                        'inserted' => $processRun->rows_inserted ?? 0,
                        'read'     => $processRun->rows_read ?? 0,
                    ];
                } catch (\Throwable $e) {
                    $results[$sourceCode] = [
                        'ok'    => false,
                        'name'  => $sourceName,
                        'error' => mb_strimwidth($e->getMessage(), 0, 200),
                    ];
                    $errors[] = "{$sourceName}: " . mb_strimwidth($e->getMessage(), 0, 120);

                    Log::warning('ReprocessPeriodUploadsJob source error', [
                        'upload_id'   => $upload->id,
                        'source_code' => $sourceCode,
                        'error'       => $e->getMessage(),
                    ]);
                }

                $completed++;
                $metadata['completed'] = $completed;
                $metadata['progress']  = self::SOURCE_PROGRESS[$sourceCode] ?? (int) (10 + ($completed / max($total, 1)) * 80);
                $run->update(['metadata' => $metadata]);
            }

            $hasErrors = !empty($errors);
            $finalLog  = $hasErrors
                ? 'Reprocesamiento terminado con ' . count($errors) . ' error(s). ' . implode('; ', $errors)
                : "Reprocesamiento completado. {$total} fuente(s) procesadas correctamente.";

            $metadata['progress'] = 100;
            $metadata['results']  = $results;

            $run->update([
                'status'      => $hasErrors ? 'failed' : 'success',
                'log'         => $finalLog,
                'metadata'    => $metadata,
                'finished_at' => now(),
            ]);

            if ($user?->email) {
                $mail = $hasErrors
                    ? new ReprocessPeriodFailedMail($period, $user, $run, $results)
                    : new ReprocessPeriodCompletedMail($period, $user, $run, $results);
                Mail::to($user->email)->queue($mail);
            }
        } catch (\Throwable $e) {
            Log::error('ReprocessPeriodUploadsJob failed unexpectedly', [
                'period_id' => $this->periodId,
                'run_id'    => $this->runId,
                'error'     => $e->getMessage(),
            ]);

            $run->update([
                'status'        => 'failed',
                'log'           => 'El reprocesamiento falló de forma inesperada.',
                'error_message' => mb_strimwidth($e->getMessage(), 0, 500),
                'finished_at'   => now(),
            ]);

            if ($user?->email) {
                Mail::to($user->email)->queue(new ReprocessPeriodFailedMail($period, $user, $run, []));
            }

            throw $e;
        }
    }
}
