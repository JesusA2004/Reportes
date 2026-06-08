<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Models\PeriodDatabaseUpdateRun;
use App\Services\DatabaseUpdateService;
use Illuminate\Console\Command;

class RunDatabaseUpdateCommand extends Command
{
    protected $signature   = 'reportes:run-database-update {period_id : ID del periodo a procesar}';
    protected $description = 'Ejecuta la actualización de BD para un periodo (crea run, llama servicio, marca success/failed).';

    public function handle(DatabaseUpdateService $service): int
    {
        set_time_limit(0);

        $periodId = (int) $this->argument('period_id');
        $period   = Period::find($periodId);

        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        $existing = PeriodDatabaseUpdateRun::query()
            ->where('period_id', $periodId)
            ->where('status', 'running')
            ->first();

        if ($existing) {
            $this->error("Ya hay un run en estado 'running' para el periodo #{$periodId} (ID: {$existing->id}). Márcalo como failed antes de continuar.");
            return self::FAILURE;
        }

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

        try {
            $service->updateForPeriod($period, $run);

            $run->update([
                'status'      => 'success',
                'log'         => 'Actualización completada exitosamente.',
                'finished_at' => now(),
            ]);

            $this->info('');
            $this->info("✓ Run #{$run->id} completado con éxito.");
            return self::SUCCESS;

        } catch (\Throwable $e) {
            $run->update([
                'status'        => 'failed',
                'log'           => 'Error durante la actualización de BD.',
                'error_message' => $e->getMessage(),
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
