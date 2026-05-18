<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\BranchResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugCarteraRawCommand extends Command
{
    protected $signature   = 'reportes:debug-cartera-raw {period_id : ID del periodo}';
    protected $description = 'Debug cartera por sucursal: saldo actual, saldo capital, mora, exclusiones.';

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
        $dataIds    = array_values(array_unique(array_merge($weeklyIds, [$period->id])));

        $this->line('');
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->info("  DEBUG CARTERA RAW — {$period->label} (ID #{$periodId})");
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('  Data IDs: [' . implode(', ', $dataIds) . ']');
        $this->line('');

        // ── Resuelve branch_id → sucursal operativa ───────────────────────────
        $operativeMap = [];
        $excludedBranchIds = [];
        foreach (DB::table('branches')->get() as $b) {
            $real = $this->resolver->resolveRealBranchFromRoute($b->name);
            if (!$real) {
                $excludedBranchIds[] = (int) $b->id;
                continue;
            }
            if ($this->resolver->isSheetBranch($real)) {
                $operativeMap[(int) $b->id] = $real;
            } else {
                $excludedBranchIds[] = (int) $b->id;
            }
        }

        // ── Totales brutos ────────────────────────────────────────────────────
        $total = DB::table('fact_portfolios')->whereIn('period_id', $dataIds);
        $totalContratos = (clone $total)->count();
        $totalBalance   = (float) (clone $total)->sum('balance');
        $totalCapital   = (float) (clone $total)->sum('capital_due');
        $totalAtrasado  = (float) (clone $total)->sum('past_due_balance');

        $this->info('── 1. Totales brutos (TODAS las sucursales, incluye Norte/Corp) ──');
        $this->line(sprintf('  Contratos   : %s', number_format($totalContratos)));
        $this->line(sprintf('  Saldo actual: $%s', number_format($totalBalance, 2)));
        $this->line(sprintf('  Saldo capital: $%s', number_format($totalCapital, 2)));
        $this->line(sprintf('  Total atrasado: $%s', number_format($totalAtrasado, 2)));
        $this->line('');

        // ── Por sucursal operativa ────────────────────────────────────────────
        $this->info('── 2. Por sucursal operativa (las 13) ──');

        $rows = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', array_keys($operativeMap))
            ->selectRaw('branch_id, COUNT(*) as contratos, SUM(balance) as saldo_actual, SUM(capital_due) as saldo_capital, SUM(past_due_balance) as total_atrasado, SUM(CASE WHEN days_past_due > 0 THEN 1 ELSE 0 END) as con_mora')
            ->groupBy('branch_id')
            ->get();

        // Accumulate by resolved sucursal
        $bySuc = [];
        foreach ($this->resolver->operativeFinancialBranches() as $suc) {
            $bySuc[$suc] = ['contratos' => 0, 'saldo_actual' => 0.0, 'saldo_capital' => 0.0, 'total_atrasado' => 0.0, 'con_mora' => 0];
        }
        foreach ($rows as $row) {
            $suc = $operativeMap[(int) $row->branch_id] ?? null;
            if (!$suc || !isset($bySuc[$suc])) {
                continue;
            }
            $bySuc[$suc]['contratos']     += (int) $row->contratos;
            $bySuc[$suc]['saldo_actual']  += (float) $row->saldo_actual;
            $bySuc[$suc]['saldo_capital'] += (float) $row->saldo_capital;
            $bySuc[$suc]['total_atrasado']+= (float) $row->total_atrasado;
            $bySuc[$suc]['con_mora']      += (int) $row->con_mora;
        }

        $tableRows = [];
        $globalSaldo = 0.0;
        foreach ($bySuc as $suc => $d) {
            $globalSaldo += $d['saldo_actual'];
            $tableRows[] = [
                $suc,
                number_format($d['contratos']),
                '$' . number_format($d['saldo_actual'], 2),
                '$' . number_format($d['saldo_capital'], 2),
                '$' . number_format($d['total_atrasado'], 2),
                $d['con_mora'],
            ];
        }
        $tableRows[] = ['GLOBAL (SUMA)', '—', '$' . number_format($globalSaldo, 2), '—', '—', '—'];

        $this->table(
            ['Sucursal', 'Contratos', 'Saldo actual', 'Saldo capital', 'Total atrasado', 'Con mora'],
            $tableRows
        );

        // ── Comparación vs target ─────────────────────────────────────────────
        $target = 39_130_054.53;
        $diff   = $globalSaldo - $target;
        $pct    = abs($diff / $target) * 100;

        $this->line('');
        $this->info('── 3. Comparación vs target ──');
        $this->line(sprintf('  Calculado (13 suc.)  : $%s', number_format($globalSaldo, 2)));
        $this->line(sprintf('  Target Abril 2026    : $%s', number_format($target, 2)));
        $status = abs($diff) < 1 ? '<fg=green>OK</>' : ($pct < 1 ? '<fg=yellow>CERCA (%.2f%%)</>' : '<fg=red>DIF (%.2f%%)</>');
        $this->line(sprintf("  Diferencia           : \$%s  %s", number_format($diff, 2), sprintf(str_replace('%.2f', number_format($pct, 2), $status))));
        $this->line('');

        // ── Sucursales excluidas del GLOBAL ───────────────────────────────────
        $this->info('── 4. Sucursales excluidas del GLOBAL (Norte/Corp/desconocido) ──');
        $excluded = DB::table('fact_portfolios as po')
            ->join('branches as b', 'po.branch_id', '=', 'b.id')
            ->whereIn('po.period_id', $dataIds)
            ->whereNotIn('po.branch_id', array_keys($operativeMap))
            ->selectRaw('b.name as branch, COUNT(*) as contratos, SUM(po.balance) as saldo, SUM(po.past_due_balance) as mora')
            ->groupBy('po.branch_id', 'b.name')
            ->orderByDesc('saldo')
            ->get();

        if ($excluded->isEmpty()) {
            $this->line('  <fg=green>✓ Ninguna fila excluida del GLOBAL.</>');
        } else {
            foreach ($excluded as $r) {
                $reason = $this->classifyExclusion($r->branch);
                $this->line(sprintf('  %-40s  contratos=%d  saldo=$%s  [%s]',
                    $r->branch,
                    $r->contratos,
                    number_format((float) $r->saldo, 2),
                    $reason
                ));
            }
        }

        // ── SAN JUAN DEL RÍO ─────────────────────────────────────────────────
        $this->line('');
        $this->info('── 5. SAN JUAN DEL RÍO — sucursal cerrada ──');
        $sjrVal   = $bySuc['SAN JUAN DEL RÍO'] ?? [];
        $sjrSaldo = (float) ($sjrVal['saldo_actual'] ?? 0);
        $this->line(sprintf('  Contratos con cartera : %d', $sjrVal['contratos'] ?? 0));
        $this->line(sprintf('  Saldo actual (cartera): $%s', number_format($sjrSaldo, 2)));
        $this->line(sprintf('  Con mora              : %d contratos', $sjrVal['con_mora'] ?? 0));
        if ($sjrSaldo > 0) {
            $this->line('  <fg=green>OK: SJR cuenta en el GLOBAL (sucursal cerrada / cartera en recuperación)</>');
        }

        $this->line('');
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('');

        return self::SUCCESS;
    }

    private function classifyExclusion(string $branchName): string
    {
        $up = strtoupper(trim($branchName));
        if (str_contains($up, 'CHIHUAHUA') || str_contains($up, 'DURANGO')) {
            return 'Norte';
        }
        if (str_contains($up, 'CORPORATIVO')) {
            return 'Corporativo';
        }
        if (str_contains($up, 'AGUASCALIENTES')) {
            return 'AGS (no en 13)';
        }
        return 'Desconocido';
    }
}
