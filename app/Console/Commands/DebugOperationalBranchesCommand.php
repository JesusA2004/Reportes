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

        // ── Lista explícita de sucursales ──────────────────────────────────
        $this->info('── Sucursales incluidas para el reporte general ─────────────────────');
        foreach ($operative as $name) {
            $this->line("  <fg=green>✓</> {$name}");
        }
        $this->line('');

        $this->info('── Sucursales excluidas ─────────────────────────────────────────────');
        $this->line('  <fg=red>✗</> SAN JUAN DEL RÍO  — Sucursal cerrada');
        $this->line('  <fg=red>✗</> CORPORATIVO       — No operativo');
        $this->line('');

        $this->line(sprintf('  Total incluidas: <fg=green>%d</>   Total excluidas conocidas: <fg=red>2</>', count($operative)));
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
            $cartera      = (float) ($b['valor_cartera'] ?? 0);
            $colocacion   = (float) ($b['colocacion'] ?? 0);
            $recuperacion = (float) ($b['recuperacion_total'] ?? 0);
            $mora         = $cartera > 0 ? ((float) ($b['mora_total'] ?? 0) / $cartera * 100) : 0.0;

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
        $gCartera      = (float) ($global['valor_cartera'] ?? 0);
        $gColocacion   = (float) ($global['colocacion'] ?? 0);
        $gRecuperacion = (float) ($global['recuperacion_total'] ?? 0);
        $gMora         = $gCartera > 0 ? ((float) ($global['mora_total'] ?? 0) / $gCartera * 100) : 0.0;

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

        // SAN JUAN DEL RÍO y CORPORATIVO NO deben aparecer en los resultados operativos
        foreach (['SAN JUAN DEL RÍO', 'CORPORATIVO'] as $excludedName) {
            if (in_array($excludedName, $included, true)) {
                $this->line("  <fg=red>ERROR: {$excludedName} apareció en los resultados — debería estar excluida.</>");
                $warnings++;
            } else {
                $this->line("  <fg=green>{$excludedName} correctamente excluida.</>");
            }
        }

        // Ubicaciones pendientes: branches en DB que NO resuelven a una sucursal operativa
        // ni a una exclusión conocida. Routes como "ORIZABA SUR" no cuentan porque
        // resolveRealBranchFromRoute("ORIZABA SUR") → "ORIZABA" (operativa).
        $this->info('── Ubicaciones pendientes de clasificación ───────────────────────────');
        $operativeNorm  = array_map(
            fn ($s) => (string) \Illuminate\Support\Str::ascii(mb_strtoupper($s)),
            $this->resolver->operativeFinancialBranches(),
        );
        $exclusionNorm  = array_map(
            fn ($s) => (string) \Illuminate\Support\Str::ascii(mb_strtoupper($s)),
            ['SAN JUAN DEL RÍO', 'CORPORATIVO'],
        );
        $allKnownNorm = array_merge($operativeNorm, $exclusionNorm);
        $normalize    = fn (string $s) => (string) \Illuminate\Support\Str::ascii(mb_strtoupper($s));

        $allBranchNames = \App\Models\Branch::pluck('name')->all();
        $pendingFound   = [];
        foreach ($allBranchNames as $branchName) {
            // Try to resolve to real branch via route map
            $resolved     = $this->resolver->resolveRealBranchFromRoute($branchName);
            $resolvedNorm = $resolved ? $normalize($resolved) : null;

            if ($resolvedNorm && in_array($resolvedNorm, $allKnownNorm, true)) {
                continue; // resuelve a operativa o exclusión conocida → OK
            }

            $rawNorm = $normalize($branchName);
            if (in_array($rawNorm, $allKnownNorm, true)) {
                continue; // es directamente una sucursal operativa o exclusión → OK
            }

            // No se puede resolver → pendiente
            if (!in_array($branchName, $pendingFound, true)) {
                $pendingFound[] = $branchName;
            }
        }

        if (empty($pendingFound)) {
            $this->line('  <fg=green>Ninguna ubicación pendiente detectada.</>');
        } else {
            foreach ($pendingFound as $name) {
                $this->line("  <fg=yellow>⚠ Pendiente:</> {$name}");
            }
            $warnings += count($pendingFound);
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
