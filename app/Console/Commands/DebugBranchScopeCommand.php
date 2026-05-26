<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\BranchResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Shows how every branch row in the DB resolves for the given period:
 * which fact rows it owns, whether it's operative, and why it's included/excluded.
 */
class DebugBranchScopeCommand extends Command
{
    protected $signature   = 'reportes:debug-branch-scope {period_id : ID del periodo mensual}';
    protected $description = 'Muestra cómo resuelve cada sucursal de la BD para el periodo dado (operativa, corporativo, fuera de alcance, sin resolver).';

    public function __construct(private readonly BranchResolverService $resolver)
    {
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
        $this->info("  DEBUG BRANCH SCOPE — {$period->label} (ID #{$periodId})");
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('  Data IDs: [' . implode(', ', $dataIds) . ']');
        $this->line('');

        $branches = DB::table('branches')->orderBy('name')->get();

        // Count fact rows per branch_id across the main fact tables
        $factCounts = [];
        $factTables = ['fact_portfolios', 'fact_recoveries', 'fact_placements', 'fact_expenses', 'fact_noi_movements'];
        foreach ($factTables as $tbl) {
            $rows = DB::table($tbl)
                ->whereIn('period_id', $dataIds)
                ->selectRaw('branch_id, COUNT(*) as cnt')
                ->groupBy('branch_id')
                ->get();
            foreach ($rows as $r) {
                $factCounts[(int) $r->branch_id][$tbl] = (int) $r->cnt;
            }
        }

        $operative    = $this->resolver->operativeFinancialBranches();
        $operativeSet = array_map('strtoupper', $operative);

        $counts = ['operative' => 0, 'closed' => 0, 'corporativo' => 0, 'out_of_scope' => 0, 'unresolved' => 0];

        foreach ($branches as $b) {
            $real  = $this->resolver->resolveRealBranchFromRoute($b->name);
            $upper = $real ? strtoupper(trim($real)) : null;

            if ($upper && in_array($upper, $operativeSet, true)) {
                $tag = '<fg=green>OPERATIVA    </>';
                $counts['operative']++;
            } elseif ($upper === 'SAN JUAN DEL RÍO' || $upper === 'SAN JUAN DEL RIO') {
                $tag = '<fg=yellow>CERRADA      </>';
                $counts['closed']++;
            } elseif ($upper === 'CORPORATIVO') {
                $tag = '<fg=cyan>CORPORATIVO  </>';
                $counts['corporativo']++;
            } elseif ($upper) {
                $tag = '<fg=magenta>FUERA ALCANCE</>';
                $counts['out_of_scope']++;
            } else {
                $tag = '<fg=red>SIN RESOLVER </>';
                $counts['unresolved']++;
            }

            $totalFacts = array_sum($factCounts[(int) $b->id] ?? []);
            $factsStr   = $totalFacts > 0 ? "<fg=white>{$totalFacts} filas</>" : '<fg=gray>sin datos</>';

            $this->line(sprintf(
                '  %s  %-40s → %-28s  %s',
                $tag,
                mb_strimwidth($b->name, 0, 40, '…'),
                $real ?? '—',
                $factsStr
            ));
        }

        $this->line('');
        $this->info('── Resumen ──────────────────────────────────────────────────────────');
        $this->line(sprintf('  <fg=green>Operativas     :</> %d (de 12 esperadas)', $counts['operative']));
        $this->line(sprintf('  <fg=yellow>Cerradas       :</> %d', $counts['closed']));
        $this->line(sprintf('  <fg=cyan>Corporativo    :</> %d', $counts['corporativo']));
        $this->line(sprintf('  <fg=magenta>Fuera de alcance:</> %d', $counts['out_of_scope']));
        $this->line(sprintf('  <fg=red>Sin resolver   :</> %d', $counts['unresolved']));
        $this->line('');

        if ($counts['operative'] !== 12) {
            $this->warn("⚠  Se esperaban 12 sucursales operativas, se encontraron {$counts['operative']}.");
        } else {
            $this->info('<fg=green>✓ Las 12 sucursales operativas están correctamente resueltas.</>');
        }

        $this->line('');
        return self::SUCCESS;
    }
}
