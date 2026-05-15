<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Support\RegionNorteFilter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugProductosCommand extends Command
{
    protected $signature   = 'reportes:debug-productos {period_id : ID del periodo mensual}';
    protected $description = 'Diagnóstico de productos: colocación, recuperación y cartera por producto normalizado. Detecta desajustes entre fuentes.';

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
        $this->info("══════════════════════════════════════════════════════════════");
        $this->info("  DEBUG PRODUCTOS — {$period->label} (ID #{$periodId})");
        $this->info("══════════════════════════════════════════════════════════════");
        $this->line("  Data IDs: [" . implode(', ', $dataIds) . "]");
        $this->line('');

        $nonOp = RegionNorteFilter::names();

        // ── 1. Productos en Ministraciones (colocación) ────────────────
        $this->info('── 1. Colocación por producto (fact_placements, operativas) ──');
        $placements = DB::table('fact_placements as p')
            ->leftJoin('branches as b', 'p.branch_id', '=', 'b.id')
            ->whereIn('p.period_id', $dataIds)
            ->where(function ($q) use ($nonOp) { $q->whereNull('b.name')->orWhereNotIn(DB::raw('LOWER(b.name)'), $nonOp); })
            ->whereRaw("(b.name IS NULL OR LOWER(b.name) NOT LIKE ?)", ['%inactiva%'])
            ->selectRaw("COALESCE(p.product_name, '(sin producto)') as producto, COUNT(*) as ops, SUM(p.amount) as total")
            ->groupByRaw("COALESCE(p.product_name, '(sin producto)')")
            ->orderByDesc('total')
            ->get();

        $totalPlac = 0;
        foreach ($placements as $row) {
            $this->line(sprintf("  %-22s  %4d ops  $%s", $row->producto, $row->ops, number_format((float)$row->total, 2)));
            $totalPlac += (float)$row->total;
        }
        $this->line(sprintf("  %-22s  %4d ops  $%s", 'TOTAL', $placements->sum('ops'), number_format($totalPlac, 2)));
        $this->line('');

        // ── 2. Productos en Cobranza (recuperación) ────────────────────
        $this->info('── 2. Recuperación por producto (fact_recoveries, operativas, solo PAGO) ──');
        $recoveries = RegionNorteFilter::applyRecoveryFilter(
            DB::table('fact_recoveries as r')
                ->leftJoin('branches as b', 'r.branch_id', '=', 'b.id')
                ->whereIn('r.period_id', $dataIds)
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, '$.transaction')) = 'PAGO'")
        )
            ->selectRaw("COALESCE(r.product_name, '(sin producto)') as producto, COUNT(*) as ops, SUM(r.total_amount) as total")
            ->groupByRaw("COALESCE(r.product_name, '(sin producto)')")
            ->orderByDesc('total')
            ->get();

        $totalRec = 0;
        foreach ($recoveries as $row) {
            $this->line(sprintf("  %-22s  %4d ops  $%s", $row->producto, $row->ops, number_format((float)$row->total, 2)));
            $totalRec += (float)$row->total;
        }
        $this->line(sprintf("  %-22s  %4d ops  $%s", 'TOTAL', $recoveries->sum('ops'), number_format($totalRec, 2)));
        $this->line('');

        // ── 3. Productos en Cartera (saldos) ───────────────────────────
        $this->info('── 3. Cartera por producto (fact_portfolios, operativas) ──');
        $portfolio = DB::table('fact_portfolios as po')
            ->leftJoin('branches as b', 'po.branch_id', '=', 'b.id')
            ->whereIn('po.period_id', $dataIds)
            ->where(function ($q) use ($nonOp) { $q->whereNull('b.name')->orWhereNotIn(DB::raw('LOWER(b.name)'), $nonOp); })
            ->whereRaw("(b.name IS NULL OR LOWER(b.name) NOT LIKE ?)", ['%inactiva%'])
            ->selectRaw("COALESCE(po.product_name, '(sin producto)') as producto, COUNT(*) as contratos, SUM(po.balance) as total, SUM(COALESCE(po.past_due_balance,0)) as vencida")
            ->groupByRaw("COALESCE(po.product_name, '(sin producto)')")
            ->orderByDesc('total')
            ->get();

        $totalPort = 0;
        $totalVenc = 0;
        foreach ($portfolio as $row) {
            $this->line(sprintf("  %-22s  %5d cttos  cartera=$%s  vencida=$%s",
                $row->producto, $row->contratos,
                number_format((float)$row->total, 2),
                number_format((float)$row->vencida, 2)));
            $totalPort += (float)$row->total;
            $totalVenc += (float)$row->vencida;
        }
        $this->line(sprintf("  %-22s  %5d cttos  cartera=$%s  vencida=$%s",
            'TOTAL', $portfolio->sum('contratos'),
            number_format($totalPort, 2),
            number_format($totalVenc, 2)));
        $this->line('');

        // ── 4. Productos SIN cobertura en otras fuentes ────────────────
        $this->info('── 4. Productos con desajuste entre fuentes ──');

        $prodPlac = $placements->pluck('producto')->map(fn ($p) => strtolower($p))->all();
        $prodRec  = $recoveries->pluck('producto')->map(fn ($p) => strtolower($p))->all();
        $prodPort = $portfolio->pluck('producto')->map(fn ($p) => strtolower($p))->all();

        $allProds = array_unique(array_merge($prodPlac, $prodRec, $prodPort));
        sort($allProds);

        $rows = [];
        foreach ($allProds as $p) {
            $inPlac = in_array($p, $prodPlac) ? '✓' : ' ';
            $inRec  = in_array($p, $prodRec)  ? '✓' : ' ';
            $inPort = in_array($p, $prodPort) ? '✓' : ' ';
            if ($inPlac !== '✓' || $inRec !== '✓' || $inPort !== '✓') {
                $rows[] = [$p, $inPlac, $inRec, $inPort];
            }
        }

        if (empty($rows)) {
            $this->line('  <fg=green>Todos los productos aparecen en las 3 fuentes.</>');
        } else {
            $this->table(['Producto', 'Ministr', 'Cobranza', 'Cartera'], $rows);
        }
        $this->line('');

        // ── 5. Productos "(sin producto)" con monto ────────────────────
        $this->info('── 5. Registros sin producto asignado (revisar normalización) ──');
        $sinProdPlac = $placements->firstWhere('producto', '(sin producto)');
        $sinProdRec  = $recoveries->firstWhere('producto', '(sin producto)');
        $sinProdPort = $portfolio->firstWhere('producto', '(sin producto)');

        if ($sinProdPlac) {
            $this->line(sprintf('  Ministraciones sin producto: %d ops  $%s', $sinProdPlac->ops, number_format((float)$sinProdPlac->total, 2)));
        }
        if ($sinProdRec) {
            $this->line(sprintf('  Cobranza sin producto:       %d ops  $%s', $sinProdRec->ops, number_format((float)$sinProdRec->total, 2)));
        }
        if ($sinProdPort) {
            $this->line(sprintf('  Cartera sin producto:        %d cttos $%s', $sinProdPort->contratos, number_format((float)$sinProdPort->total, 2)));
        }
        if (!$sinProdPlac && !$sinProdRec && !$sinProdPort) {
            $this->line('  <fg=green>Ninguno — todos los registros tienen producto asignado.</>');
        }
        $this->line('');

        // ── 6. Top 10 nombres RAW en cobranza (sin normalizar) ────────
        $this->info('── 6. Muestra de nombres RAW en Ingresos Cobranza (operativas, PAGO) ──');
        $rawCobranza = RegionNorteFilter::applyRecoveryFilter(
            DB::table('fact_recoveries as r')
                ->leftJoin('branches as b', 'r.branch_id', '=', 'b.id')
                ->whereIn('r.period_id', $dataIds)
                ->whereNotNull('r.product_name')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, '$.transaction')) = 'PAGO'")
        )
            ->selectRaw('r.product_name, COUNT(*) as cnt, SUM(r.total_amount) as total')
            ->groupBy('r.product_name')
            ->orderByDesc('total')
            ->limit(20)
            ->get();

        foreach ($rawCobranza as $row) {
            $this->line(sprintf('  %-30s  %4d  $%s', $row->product_name, $row->cnt, number_format((float)$row->total, 2)));
        }
        $this->line('');

        $this->info("══════════════════════════════════════════════════════════════");
        $this->line('');

        return self::SUCCESS;
    }
}
