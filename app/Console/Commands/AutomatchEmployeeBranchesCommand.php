<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\EmployeeBranchAutoMatchService;
use Illuminate\Console\Command;

class AutomatchEmployeeBranchesCommand extends Command
{
    protected $signature = 'reportes:automatch-employee-branches
                                {period_id : ID del periodo mensual}
                                {--apply : Ejecuta y guarda las asignaciones (sin esta bandera solo valida que el periodo exista)}
                                {--detail : Muestra el detalle de tiempos y conteos de consultas}';

    protected $description = 'Reconstruye las asignaciones automáticas de empleado→sucursal de un periodo (preserva manuales), sin reprocesar NOI/Rotación/IMSS/Cobranza/Cartera/Colocación/Gastos.';

    public function handle(EmployeeBranchAutoMatchService $service): int
    {
        $periodId = (int) $this->argument('period_id');
        $period   = Period::find($periodId);

        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        $this->line('');
        $this->info('══════════════════════════════════════════════════');
        $this->info("  AUTOMATCH EMPLEADO→SUCURSAL — {$period->label} (ID {$periodId})");
        $this->info('══════════════════════════════════════════════════');

        if (!$this->option('apply')) {
            $this->line('  Modo validación (sin --apply): no se escriben cambios.');
            $this->line("  Ejecuta: php artisan reportes:automatch-employee-branches {$periodId} --apply");
            return self::SUCCESS;
        }

        $bar = null;
        $lastStep = '';

        $progress = function (int $percent, string $step, string $log) use (&$bar, &$lastStep) {
            if ($step !== $lastStep) {
                $this->line(sprintf('  [%3d%%] %s — %s', $percent, $step, $log));
                $lastStep = $step;
            }
        };

        $result = $service->handle($periodId, $progress);

        $this->line('');
        $this->info('  ✓ Automatch finalizado.');
        $this->line('');
        $this->line(sprintf('  %-28s %s', 'Empleados analizados:', $result['processed']));
        $this->line(sprintf('  %-28s %s', 'Personas únicas:', $result['unique_persons']));
        $this->line(sprintf('  %-28s %s', 'Manuales respetadas:', $result['manual_kept']));
        $this->line(sprintf('  %-28s %s', 'Históricos:', $result['historical']));
        $this->line(sprintf('  %-28s %s', 'Nombre exacto:', $result['exact_name']));
        $this->line(sprintf('  %-28s %s', 'Mayoría:', $result['majority']));
        $this->line(sprintf('  %-28s %s', 'Fuzzy:', $result['fuzzy']));
        $this->line(sprintf('  %-28s %s', 'Total asignados:', $result['matched']));
        $this->line(sprintf('  %-28s %s', 'Ambiguos:', $result['ambiguous']));
        $this->line(sprintf('  %-28s %s', 'Sin asignar:', $result['unmatched']));
        $this->line('');

        if ($this->option('detail')) {
            $this->line(sprintf('  %-28s %d ms', 'Duración:', $result['duration_ms']));
            $this->line(sprintf('  %-28s %d', 'Consultas SQL ejecutadas:', $result['query_count']));
            $this->line('');
        }

        if ($result['unmatched'] > 0 || $result['ambiguous'] > 0) {
            $this->warn("  Quedan {$result['unmatched']} persona(s) sin asignar y {$result['ambiguous']} ambigua(s).");
            $this->line("  Revisa: php artisan reportes:audit-employee-branches {$periodId} --detail");
        } else {
            $this->info('  Todas las personas quedaron asignadas.');
        }

        return self::SUCCESS;
    }
}
