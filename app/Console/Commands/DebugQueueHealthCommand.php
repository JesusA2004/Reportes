<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugQueueHealthCommand extends Command
{
    protected $signature   = 'reportes:debug-queue-health';
    protected $description = 'Diagnóstico rápido de la cola de jobs: conexión, jobs pendientes, fallidos y sugerencias de acción.';

    public function handle(): int
    {
        $this->line('');
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->info('  DEBUG QUEUE HEALTH');
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('');

        // ── Configuración ─────────────────────────────────────────────────────
        $connection = config('queue.default');
        $driver     = config("queue.connections.{$connection}.driver");
        $this->info('── Configuración ─────────────────────────────────────────────────────');
        $this->line(sprintf('  QUEUE_CONNECTION : %s', $connection));
        $this->line(sprintf('  Driver           : %s', $driver));
        $this->line('');

        if ($driver !== 'database') {
            $this->warn('  El driver no es "database". Esta herramienta asume la tabla jobs.');
            $this->line('');
        }

        // ── Jobs pendientes ────────────────────────────────────────────────────
        $totalJobs    = DB::table('jobs')->count();
        $defaultJobs  = DB::table('jobs')->where('queue', 'default')->count();
        $reservedJobs = DB::table('jobs')->whereNotNull('reserved_at')->count();

        $this->info('── Jobs pendientes (tabla jobs) ──────────────────────────────────────');
        $this->line(sprintf('  Total jobs          : %d', $totalJobs));
        $this->line(sprintf('  En queue "default"  : %d', $defaultJobs));
        $this->line(sprintf('  Reservados (worker) : %d', $reservedJobs));
        $this->line('');

        if ($totalJobs > 0) {
            $jobs = DB::table('jobs')->orderBy('id')->limit(10)->get();
            $rows = $jobs->map(fn ($j) => [
                $j->id,
                $j->queue,
                $j->attempts,
                date('Y-m-d H:i:s', $j->available_at),
                $j->reserved_at ? date('Y-m-d H:i:s', $j->reserved_at) : '—',
                mb_strimwidth(json_decode($j->payload, true)['displayName'] ?? '?', 0, 50),
            ])->all();
            $this->table(['ID', 'Queue', 'Attempts', 'Available at', 'Reserved at', 'Job class'], $rows);
            $this->line('');

            if ($reservedJobs === 0) {
                $this->warn('  DIAGNÓSTICO: Hay jobs pendientes pero ninguno está siendo procesado.');
                $this->warn('  El worker no está activo o está procesando otra cola.');
                $this->line('');
                $this->line('  Para procesar la cola:');
                $this->line('  php artisan queue:work --tries=1 --timeout=0 -vvv');
                $this->line('');
                $this->line('  Para procesar solo un job y ver el output:');
                $this->line('  php artisan queue:work --once -vvv');
                $this->line('');
            } else {
                $this->line('  Hay jobs reservados — worker activo.');
                $this->line('');
            }
        } else {
            $this->line('  <fg=green>No hay jobs pendientes en la cola.</> ✓');
            $this->line('');
        }

        // ── Failed jobs ────────────────────────────────────────────────────────
        $failedCount = DB::table('failed_jobs')->count();
        $this->info('── Jobs fallidos (tabla failed_jobs) ─────────────────────────────────');
        $this->line(sprintf('  Total fallidos : %d', $failedCount));
        $this->line('');

        if ($failedCount > 0) {
            $failed = DB::table('failed_jobs')->orderByDesc('id')->limit(5)->get();
            $rows = $failed->map(fn ($f) => [
                $f->uuid,
                $f->queue,
                $f->failed_at,
                mb_strimwidth(json_decode($f->payload, true)['displayName'] ?? '?', 0, 40),
                mb_strimwidth($f->exception ?? '', 0, 60),
            ])->all();
            $this->table(['UUID', 'Queue', 'Failed at', 'Job class', 'Exception (truncado)'], $rows);
            $this->line('');
            $this->warn('  Para reintentar fallidos: php artisan queue:retry all');
            $this->warn('  Para limpiar fallidos:    php artisan queue:flush');
            $this->line('');
        } else {
            $this->line('  <fg=green>No hay jobs fallidos.</> ✓');
            $this->line('');
        }

        // ── UpdatePeriodDatabaseJob específicamente ────────────────────────────
        $dbUpdateJobs = DB::table('jobs')
            ->where('payload', 'LIKE', '%UpdatePeriodDatabaseJob%')
            ->orderBy('id')
            ->get();

        $this->info('── UpdatePeriodDatabaseJob en cola ───────────────────────────────────');
        $this->line(sprintf('  Total : %d', $dbUpdateJobs->count()));

        foreach ($dbUpdateJobs as $j) {
            $payload = json_decode($j->payload, true);
            preg_match('/periodId";i:(\d+);s:\d+:"runId";i:(\d+)/', $payload['data']['command'] ?? '', $m);
            $periodId = $m[1] ?? '?';
            $runId    = $m[2] ?? '?';
            $this->line(sprintf('  job_id=%d | period_id=%s | run_id=%s | attempts=%d | reserved=%s',
                $j->id, $periodId, $runId, $j->attempts,
                $j->reserved_at ? date('H:i:s', $j->reserved_at) : 'no'
            ));
        }

        if ($dbUpdateJobs->isEmpty()) {
            $this->line('  Ninguno en cola.');
        }
        $this->line('');

        // ── period_database_update_runs activos ────────────────────────────────
        $activeRuns = DB::table('period_database_update_runs')
            ->whereIn('status', ['queued', 'running'])
            ->orderByDesc('id')
            ->get();

        $this->info('── Runs activos (period_database_update_runs) ────────────────────────');
        $this->line(sprintf('  Total activos : %d', $activeRuns->count()));

        foreach ($activeRuns as $r) {
            $hasJob = DB::table('jobs')
                ->where('payload', 'LIKE', '%UpdatePeriodDatabaseJob%')
                ->where('payload', 'LIKE', '%runId%i:' . $r->id . ';%')
                ->exists();

            $status = $hasJob
                ? ($r->status === 'queued' ? '<fg=yellow>queued (job en cola)</>' : '<fg=cyan>running</>')
                : '<fg=red>queued SIN JOB (huérfano)</>';

            $this->line(sprintf('  run_id=%d | period_id=%d | %s | queued_at=%s',
                $r->id, $r->period_id, $status,
                $r->queued_at ?? $r->created_at
            ));

            if (!$hasJob && $r->status === 'queued') {
                $this->warn('    → Run huérfano. Usa "Reencolar job" en UI o cancela y vuelve a iniciar.');
            }
        }

        if ($activeRuns->isEmpty()) {
            $this->line('  Ningún run activo.');
        }
        $this->line('');

        // ── Resumen / veredicto ────────────────────────────────────────────────
        $this->info('── Veredicto ─────────────────────────────────────────────────────────');
        if ($totalJobs === 0 && $activeRuns->isEmpty()) {
            $this->line('  <fg=green>Sistema en reposo. No hay jobs pendientes ni runs activos.</> ✓');
        } elseif ($totalJobs > 0 && $reservedJobs === 0) {
            $this->warn('  Hay jobs en cola pero el worker NO está activo.');
            $this->line('  Inicia el worker:');
            $this->line('  php artisan queue:work --tries=1 --timeout=0 -vvv');
        } elseif ($reservedJobs > 0) {
            $this->line('  <fg=green>Worker activo — hay jobs siendo procesados.</> ✓');
        } else {
            $this->line('  Estado desconocido. Revisa los detalles arriba.');
        }
        $this->line('');

        return self::SUCCESS;
    }
}
