<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Models\PeriodDatabaseUpdateRun;
use App\Services\DatabaseUpdateService;
use App\Services\PeriodDerivedDataCleaner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RunDatabaseUpdateCommand extends Command
{
    protected $signature = 'reportes:run-database-update
        {period_id : ID del periodo a procesar}
        {--force-reset : Marca runs running como failed, limpia datos derivados y re-ejecuta desde cero}';

    protected $description = 'Ejecuta la actualización de BD para un periodo (crea run, llama servicio, marca success/failed).';

    public function handle(DatabaseUpdateService $service, PeriodDerivedDataCleaner $cleaner): int
    {
        set_time_limit(0);

        $periodId    = (int) $this->argument('period_id');
        $forceReset  = $this->option('force-reset');
        $period      = Period::find($periodId);

        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        // ── --force-reset: limpiar estado anterior ────────────────────────────
        if ($forceReset) {
            $this->warn("--force-reset activado para periodo #{$periodId} ({$period->label})");

            $stuckRuns = PeriodDatabaseUpdateRun::query()
                ->where('period_id', $periodId)
                ->where('status', 'running')
                ->get();

            foreach ($stuckRuns as $stuck) {
                $stuck->update([
                    'status'        => 'failed',
                    'log'           => 'Marcado como failed por --force-reset antes de re-ejecución.',
                    'error_message' => 'Interrupted: --force-reset',
                    'finished_at'   => now(),
                ]);
                $this->line("  → Run #{$stuck->id} marcado como failed.");
            }

            // Limpiar employee_branch_assignments (no los borra PeriodDerivedDataCleaner)
            $deleted = DB::table('employee_branch_assignments')->where('period_id', $periodId)->delete();
            $this->line("  → employee_branch_assignments eliminados: {$deleted}");

            // clearForPeriod limpia fact_*, period_summaries, uploads → pending
            $cleaner->clearForPeriod($period);
            $this->line("  → Datos derivados del periodo limpiados (fact_*, summaries, uploads → pending).");
            $this->line('');
        }

        // ── Bloquear si ya hay un run running ────────────────────────────────
        $existing = PeriodDatabaseUpdateRun::query()
            ->where('period_id', $periodId)
            ->where('status', 'running')
            ->first();

        if ($existing) {
            $this->error("Ya hay un run en estado 'running' para el periodo #{$periodId} (ID: {$existing->id}).");
            $this->line("Usa --force-reset para limpiarlo y re-ejecutar desde cero.");
            return self::FAILURE;
        }

        // ── Crear run ─────────────────────────────────────────────────────────
        $run = PeriodDatabaseUpdateRun::create([
            'period_id'  => $periodId,
            'created_by' => null,
            'status'     => 'running',
            'log'        => 'Iniciando actualización de BD…',
            'metadata'   => ['source' => 'artisan:run-database-update'],
            'started_at' => now(),
        ]);

        $this->info("Run #{$run->id} creado — periodo: {$period->label} (ID {$periodId})");
        $this->line('');

        // ── Shutdown handler: marca el run como failed si PHP muere ──────────
        $runId = $run->id;
        register_shutdown_function(function () use ($runId) {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $stuck = PeriodDatabaseUpdateRun::find($runId);
                if ($stuck && $stuck->status === 'running') {
                    $stuck->update([
                        'status'        => 'failed',
                        'log'           => 'Proceso PHP terminó inesperadamente (fatal error).',
                        'error_message' => $error['message'] . ' in ' . $error['file'] . ':' . $error['line'],
                        'finished_at'   => now(),
                    ]);
                }
            }
        });

        // ── Ejecutar servicio con log de progreso por fuente ─────────────────
        try {
            $startTotal = microtime(true);
            $this->line('Iniciando procesamiento…');

            $service->updateForPeriod($period, $run);

            $elapsed = round(microtime(true) - $startTotal, 1);

            $run->update([
                'status'      => 'success',
                'log'         => "Actualización completada en {$elapsed}s.",
                'finished_at' => now(),
            ]);

            $this->info('');
            $this->info("✓ Run #{$run->id} completado en {$elapsed}s.");
            return self::SUCCESS;

        } catch (\Throwable $e) {
            $run->update([
                'status'        => 'failed',
                'log'           => 'Error durante la actualización de BD.',
                'error_message' => mb_strimwidth($e->getMessage(), 0, 2000),
                'finished_at'   => now(),
            ]);

            $this->error('');
            $this->error("✗ Run #{$run->id} falló:");
            $this->error('  ' . $e->getMessage());

            if ($this->getOutput()->isVerbose()) {
                $this->line('');
                $this->line($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }
}
