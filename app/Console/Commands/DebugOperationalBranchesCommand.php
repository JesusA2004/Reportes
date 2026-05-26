<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\BranchResolverService;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Console\Command;

/**
 * Displays the 12 operative branches with key metrics for a given period,
 * and flags any unexpected inclusions or exclusions.
 */
class DebugOperationalBranchesCommand extends Command
{
    protected $signature   = 'reportes:debug-operational-branches {period_id : ID del periodo mensual}';
    protected $description = 'Lista las 12 sucursales operativas con métricas clave para el periodo dado. Alerta si alguna aparece vacía o falta.';

    public function __construct(
        private readonly BranchRadiographyCalculator $calculator,
        private readonly BranchResolverService $resolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $periodId = (int) $this->argument('period_id');
        $period   = Period::find($periodId);

        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(
            empty($weeklyIds) ? [] : $weeklyIds,
            [$period->id]
        )));

        $this->line('');
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->info("  DEBUG OPERATIONAL BRANCHES — {$period->label} (ID #{$periodId})");
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('  Data IDs: [' . implode(', ', $dataIds) . ']');
        $this->line('');

        $operative = $this->resolver->operativeFinancialBranches();
        $this->line(sprintf('  Sucursales operativas configuradas: <fg=green>%d</>', count($operative)));
        $this->line('');

        $result   = $this->calculator->buildBranches($period, $dataIds);
        $branches = $result['branches'];

        $warnings = 0;

        $this->info('── Métricas por sucursal ────────────────────────────────────────────');
        $this->line(sprintf(
            '  %-26s  %14s  %14s  %14s  %8s',
            'SUCURSAL', 'CARTERA', 'COLOCACIÓN', 'RECUPERACIÓN', 'MORA %'
        ));
        $this->line('  ' . str_repeat('─', 82));

        foreach ($branches as $b) {
            $cartera     = (float) $b['valor_cartera'];
            $colocacion  = (float) $b['colocacion'];
            $recuperacion = (float) $b['recuperacion_total'];
            $mora        = $cartera > 0 ? ((float) $b['mora_total'] / $cartera * 100) : 0.0;

            $allZero = ($cartera + $colocacion + $recuperacion) < 1.0;
            if ($allZero) {
                $warnings++;
            }
            $flag = $allZero ? ' <fg=yellow>⚠ sin datos</>' : '';

            $this->line(sprintf(
                '  %-26s  %14s  %14s  %14s  %7.2f%%%s',
                mb_strimwidth($b['sucursal'], 0, 26, '…'),
                '$' . number_format($cartera, 0),
                '$' . number_format($colocacion, 0),
                '$' . number_format($recuperacion, 0),
                $mora,
                $flag
            ));
        }

        // Global totals
        $global = $this->calculator->sumGlobal($branches, $result['unassigned'] ?? []);
        $gCartera     = (float) $global['valor_cartera'];
        $gColocacion  = (float) $global['colocacion'];
        $gRecuperacion = (float) $global['recuperacion_total'];
        $gMora        = $gCartera > 0 ? ((float) $global['mora_total'] / $gCartera * 100) : 0.0;

        $this->line('  ' . str_repeat('─', 82));
        $this->line(sprintf(
            '  %-26s  %14s  %14s  %14s  %7.2f%%',
            'GLOBAL (' . count($branches) . ' sucursales)',
            '$' . number_format($gCartera, 0),
            '$' . number_format($gColocacion, 0),
            '$' . number_format($gRecuperacion, 0),
            $gMora
        ));
        $this->line('');

        // Exclusions check
        $this->info('── Sucursales excluidas (no aparecen arriba) ────────────────────────');
        $included = array_column($branches, 'sucursal');
        $excluded = array_diff($this->resolver->operativeFinancialBranches(), $included);
        if (empty($excluded)) {
            $this->line('  <fg=green>Ninguna excluida — las 12 sucursales están presentes.</>');
        } else {
            foreach ($excluded as $name) {
                $this->line("  <fg=red>FALTA: {$name}</>");
                $warnings++;
            }
        }

        // SAN JUAN DEL RÍO should NOT appear
        $hasClosedBranch = in_array('SAN JUAN DEL RÍO', $included, true);
        if ($hasClosedBranch) {
            $this->line('  <fg=red>ERROR: SAN JUAN DEL RÍO apareció en los resultados — debería estar excluida.</>');
            $warnings++;
        } else {
            $this->line('  <fg=green>SAN JUAN DEL RÍO correctamente excluida.</>');
        }

        $this->line('');

        if ($warnings > 0) {
            $this->warn("Se encontraron {$warnings} advertencia(s). Revisa los datos cargados para este periodo.");
            return self::FAILURE;
        }

        $this->info('<fg=green>✓ Las 12 sucursales operativas tienen datos y están correctamente configuradas.</>');
        $this->line('');

        return self::SUCCESS;
    }
}
