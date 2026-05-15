<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\BranchResolverService;
use App\Support\OperationalExclusion;
use App\Support\RegionNorteFilter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugMoraCommand extends Command
{
    protected $signature   = 'reportes:debug-mora {period_id : ID del periodo mensual}';
    protected $description = 'Diagnóstico de mora: buckets, columnas usadas, totales por sucursal, rutas no resueltas.';

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

        $nonOp = array_merge(RegionNorteFilter::names(), OperationalExclusion::names());

        $this->line('');
        $this->info("══════════════════════════════════════════════════════════════");
        $this->info("  DEBUG MORA — {$period->label} (ID #{$periodId})");
        $this->info("══════════════════════════════════════════════════════════════");
        $this->line("  Data IDs: [" . implode(', ', $dataIds) . "]");
        $this->line('');

        // ── 1. Totales brutos de columnas de mora ──────────────────────
        $this->info('── 1. Columnas de mora (totales brutos en BD) ──');
        $totals = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->selectRaw("
                COUNT(*) as contratos,
                SUM(balance) as balance_total,
                SUM(COALESCE(past_due_balance,0)) as past_due_total,
                SUM(COALESCE(capital_due,0)) as capital_due_total,
                COUNT(CASE WHEN days_past_due > 0 THEN 1 END) as contratos_vencidos,
                COUNT(CASE WHEN days_past_due IS NULL OR days_past_due = 0 THEN 1 END) as contratos_al_corriente,
                COUNT(CASE WHEN days_past_due IS NULL THEN 1 END) as dias_vencido_nulo
            ")
            ->first();

        $this->line(sprintf('  Total contratos           : %d', $totals->contratos));
        $this->line(sprintf('  balance (Saldo actual)    : $%s', number_format((float)$totals->balance_total, 2)));
        $this->line(sprintf('  past_due_balance          : $%s  ← mora usada en cálculos', number_format((float)$totals->past_due_total, 2)));
        $this->line(sprintf('  capital_due               : $%s', number_format((float)$totals->capital_due_total, 2)));
        $this->line(sprintf('  Contratos vencidos        : %d', $totals->contratos_vencidos));
        $this->line(sprintf('  Contratos al corriente    : %d', $totals->contratos_al_corriente));
        $this->line(sprintf('  días_vencido = NULL       : %d  ← deben ser 0 o un número', $totals->dias_vencido_nulo));
        $this->line('');

        // ── 2. Buckets de mora (con filtro operativo) ─────────────────
        $this->info('── 2. Mora por bucket (sucursales operativas, past_due_balance) ──');

        $buckets = [
            ['label' => '0-30',        'min' => 1,   'max' => 30,    'target' => 1_057_765.00],
            ['label' => '31-60',       'min' => 31,  'max' => 60,    'target' => 926_001.80],
            ['label' => '61-90',       'min' => 61,  'max' => 90,    'target' => 913_414.71],
            ['label' => '91-120',      'min' => 91,  'max' => 120,   'target' => 816_584.91],
            ['label' => '120+ (ref)',  'min' => 121, 'max' => 99999, 'target' => 10_259_891.95],
        ];

        $sumOf4 = 0.0;
        foreach ($buckets as $b) {
            $row = DB::table('fact_portfolios as po')
                ->leftJoin('branches as br', 'po.branch_id', '=', 'br.id')
                ->whereIn('po.period_id', $dataIds)
                ->where(function ($q) use ($nonOp) {
                    $q->whereNull('br.name')->orWhereNotIn(DB::raw('LOWER(br.name)'), $nonOp);
                })
                ->whereRaw("(br.name IS NULL OR LOWER(br.name) NOT LIKE ?)", ['%inactiva%'])
                ->whereBetween('po.days_past_due', [$b['min'], $b['max']])
                ->selectRaw('COUNT(*) as cnt, SUM(COALESCE(po.past_due_balance,0)) as mora')
                ->first();

            $mora   = (float)($row->mora ?? 0);
            $target = $b['target'];
            $diff   = $mora - $target;
            $ok     = abs($diff) < 100 ? '<fg=green>✓</>' : (abs($diff) < 5000 ? '<fg=yellow>~</>' : '<fg=red>✗</>');

            if ($b['label'] !== '120+ (ref)') {
                $sumOf4 += $mora;
            }

            $this->line(sprintf(
                '  %s Mora %-12s contratos=%-5d calculado=$%-14s target=$%-14s dif=%s',
                $ok,
                $b['label'],
                $row->cnt ?? 0,
                number_format($mora, 2),
                number_format($target, 2),
                number_format($diff, 2)
            ));
        }
        $this->line(sprintf('  %-17s SUMA 0-120: $%s  target=$%s  dif=%s',
            '',
            number_format($sumOf4, 2),
            number_format(3_713_766.42, 2),
            number_format($sumOf4 - 3_713_766.42, 2)
        ));
        $this->line('');

        // ── 3. Mora por sucursal ───────────────────────────────────────
        $this->info('── 3. Mora por sucursal (past_due_balance, excluye no-operativas) ──');
        $byBranch = DB::table('fact_portfolios as po')
            ->leftJoin('branches as br', 'po.branch_id', '=', 'br.id')
            ->whereIn('po.period_id', $dataIds)
            ->where('po.days_past_due', '>', 0)
            ->where(function ($q) use ($nonOp) {
                $q->whereNull('br.name')->orWhereNotIn(DB::raw('LOWER(br.name)'), $nonOp);
            })
            ->whereRaw("(br.name IS NULL OR LOWER(br.name) NOT LIKE ?)", ['%inactiva%'])
            ->selectRaw('COALESCE(br.name, "(sin sucursal)") as sucursal, COUNT(*) as cnt, SUM(COALESCE(po.past_due_balance,0)) as mora')
            ->groupBy('po.branch_id', 'br.name')
            ->orderByDesc('mora')
            ->get();

        $totalMoraBranch = 0.0;
        foreach ($byBranch as $row) {
            $this->line(sprintf('  %-30s contratos=%d  mora=$%s', $row->sucursal, $row->cnt, number_format((float)$row->mora, 2)));
            $totalMoraBranch += (float)$row->mora;
        }
        $this->line(sprintf('  %-30s TOTAL: $%s', '', number_format($totalMoraBranch, 2)));
        $this->line('');

        // ── 4. No-operativas excluidas ─────────────────────────────────
        $this->info('── 4. Sucursales NO operativas excluidas de mora ──');
        $excluded = DB::table('fact_portfolios as po')
            ->join('branches as br', 'po.branch_id', '=', 'br.id')
            ->whereIn('po.period_id', $dataIds)
            ->whereIn(DB::raw('LOWER(br.name)'), $nonOp)
            ->selectRaw('br.name as sucursal, COUNT(*) as cnt, SUM(po.balance) as cartera, SUM(COALESCE(po.past_due_balance,0)) as mora')
            ->groupBy('po.branch_id', 'br.name')
            ->get();

        if ($excluded->isEmpty()) {
            $this->line('  Ninguna no-operativa en fact_portfolios para este periodo.');
        } else {
            foreach ($excluded as $row) {
                $this->line(sprintf('  %-20s contratos=%d  cartera=$%s  mora=$%s',
                    $row->sucursal, $row->cnt,
                    number_format((float)$row->cartera, 2),
                    number_format((float)$row->mora, 2)));
            }
        }
        $this->line('');

        // ── 5. Cartera total debug (con/sin FALSO) ────────────────────
        $this->info('── 5. Cartera total — debug de exclusiones ──');
        $brutoBal = (float) DB::table('fact_portfolios')->whereIn('period_id', $dataIds)->sum('balance');

        foreach (['aguascalientes' => 344_425.37, 'falso' => 601.41, 'chihuahua' => 0.0, 'durango' => 0.0, 'corporativo' => 0.0] as $name => $ref) {
            $excl = (float) DB::table('fact_portfolios as po')
                ->join('branches as br', 'po.branch_id', '=', 'br.id')
                ->whereIn('po.period_id', $dataIds)
                ->whereRaw('LOWER(br.name) = ?', [$name])
                ->sum('po.balance');
            $marker = abs($excl - $ref) < 1.0 ? '<fg=green>✓</>' : ($excl > 0 ? '<fg=yellow>~</>' : '  ');
            $this->line(sprintf('  %s excl %-16s = $%s  (referencia=$%s)', $marker, strtoupper($name), number_format($excl, 2), number_format($ref, 2)));
        }

        $sinSucursal = (float) DB::table('fact_portfolios')->whereIn('period_id', $dataIds)->whereNull('branch_id')->sum('balance');
        $this->line(sprintf('  sin sucursal asignada = $%s  (incluidas en cartera operativa)', number_format($sinSucursal, 2)));
        $this->line(sprintf('  Total bruto balance   = $%s', number_format($brutoBal, 2)));
        $this->line(sprintf('  target cartera global = $%s', number_format(39_130_054.53, 2)));
        $this->line('');

        // ── 6. Rutas no resueltas ─────────────────────────────────────
        $this->info('── 6. Rutas/sucursales con branch_id = NULL (no resueltas) ──');
        $noRoute = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->whereNull('branch_id')
            ->selectRaw('COUNT(*) as cnt, SUM(balance) as cartera')
            ->first();

        if ((int)($noRoute->cnt ?? 0) > 0) {
            $this->warn(sprintf('  %d contratos sin sucursal  cartera=$%s', $noRoute->cnt, number_format((float)$noRoute->cartera, 2)));
            $this->line('  → Revisar resolución de rutas en LendusSaldosClienteImportService');
        } else {
            $this->line('  <fg=green>✓ Todos los contratos tienen sucursal asignada.</>');
        }
        $this->line('');

        $this->info("══════════════════════════════════════════════════════════════");
        $this->line('');

        return self::SUCCESS;
    }
}
