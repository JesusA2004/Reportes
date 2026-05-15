<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Support\RegionNorteFilter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugReestructurasCommand extends Command
{
    protected $signature   = 'reportes:debug-reestructuras {period_id : ID del periodo mensual}';
    protected $description = 'Diagnóstico de reestructuras: filas REESTRUCTURA/UNIFICACION/A LA MEDIDA/INSOLUTOS/RECURSOS PROPIOS en Ministraciones, Cobranza y Saldos con decisión sugerida.';

    private const PATTERN = 'REESTRUCTURA|UNIFICACION|UNIFICACIÓN|A LA MEDIDA|INSOLUTOS|RECURSOS PROPIOS|CREDITOS SALDOS INSOLUTOS';

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

        $nonOp = RegionNorteFilter::names();

        $this->line('');
        $this->info("══════════════════════════════════════════════════════════════");
        $this->info("  DEBUG REESTRUCTURAS — {$period->label} (ID #{$periodId})");
        $this->info("══════════════════════════════════════════════════════════════");
        $this->line("  Data IDs      : [" . implode(', ', $dataIds) . "]");
        $this->line("  Patrón        : " . self::PATTERN);
        $this->line('');

        // ── 1. MINISTRACIONES (fact_placements) ────────────────────────
        $this->info('── 1. MINISTRACIONES (fact_placements) ──');
        $this->line('  Regla: RECURSOS PROPIOS = no cuenta como colocación incremental (sin dinero nuevo).');
        $this->line('         REESTRUCTURA / UNIFICACION = revisar si hay monto incremental en raw_payload.');
        $this->line('');

        $placements = DB::table('fact_placements as p')
            ->leftJoin('branches as b', 'p.branch_id', '=', 'b.id')
            ->whereIn('p.period_id', $dataIds)
            ->whereRaw('p.product_name REGEXP ?', [self::PATTERN])
            ->selectRaw('p.product_name, COALESCE(b.name,"(sin sucursal)") as sucursal, LOWER(COALESCE(b.name,"")) as sucursal_lower, COUNT(*) as cnt, SUM(p.amount) as total')
            ->groupBy('p.product_name', 'b.id', 'b.name')
            ->orderByDesc('total')
            ->get();

        $totalPlace    = 0.0;
        $totalNoCount  = 0.0;
        $totalOpPlace  = 0.0;

        foreach ($placements as $r) {
            $isNonOp    = RegionNorteFilter::isNonOperative($r->sucursal);
            $product    = mb_strtoupper($r->product_name);
            $isRRPP     = str_contains($product, 'RECURSOS PROPIOS');

            if ($isNonOp) {
                $decision = 'NO CUENTA (sucursal no operativa)';
                $marker   = '<fg=red>✗</>';
                $totalNoCount += (float)$r->total;
            } elseif ($isRRPP) {
                $decision = 'NO CUENTA EN COLOCACIÓN (recursos propios = no hay dinero nuevo)';
                $marker   = '<fg=red>✗</>';
                $totalNoCount += (float)$r->total;
            } else {
                $decision = 'REVISAR (posible reestructura/unificación — identificar incremento neto)';
                $marker   = '<fg=yellow>~</>';
                $totalOpPlace += (float)$r->total;
            }

            $this->line(sprintf(
                '  %s %-42s %-28s cnt=%-4d total=$%s → %s',
                $marker,
                mb_substr($r->product_name, 0, 42),
                mb_substr($r->sucursal, 0, 28),
                $r->cnt,
                number_format((float)$r->total, 2),
                $decision
            ));

            $totalPlace += (float)$r->total;
        }

        $this->line('');
        $this->line(sprintf('  Total en BD                : $%s', number_format($totalPlace, 2)));
        $this->line(sprintf('  Total NO cuenta (excluido) : $%s', number_format($totalNoCount, 2)));
        $this->line(sprintf('  Total a revisar/posible    : $%s', number_format($totalOpPlace, 2)));
        $this->line('');

        // ── 2. COBRANZA (fact_recoveries) ──────────────────────────────
        $this->info('── 2. COBRANZA (fact_recoveries) ──');
        $this->line('  Regla: transaction=PAGO → CUENTA en recuperación (es pago real aunque sea reestructura).');
        $this->line('         transaction=CONDONACION/DESCUENTO → NO CUENTA en recuperación.');
        $this->line('');

        $recoveries = DB::table('fact_recoveries as r')
            ->leftJoin('branches as b', 'r.branch_id', '=', 'b.id')
            ->whereIn('r.period_id', $dataIds)
            ->whereRaw('r.product_name REGEXP ?', [self::PATTERN])
            ->selectRaw("
                r.product_name,
                COALESCE(b.name,'(sin sucursal)') as sucursal,
                LOWER(COALESCE(b.name,'')) as sucursal_lower,
                JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, '$.transaction')) as trans,
                JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, '$.concept')) as concepto,
                COUNT(*) as cnt,
                SUM(r.total_amount) as total
            ")
            ->groupByRaw("r.product_name, b.id, b.name, JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, '$.transaction')), JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, '$.concept'))")
            ->orderByDesc('total')
            ->get();

        $totalRecov        = 0.0;
        $totalRecovCounts  = 0.0;
        $totalRecovNoCount = 0.0;

        foreach ($recoveries as $r) {
            $isNonOp = RegionNorteFilter::isNonOperative($r->sucursal);
            $isPago  = ($r->trans === 'PAGO');

            if ($isNonOp) {
                $decision = 'NO CUENTA (sucursal no operativa)';
                $marker   = '<fg=red>✗</>';
                $totalRecovNoCount += (float)$r->total;
            } elseif ($isPago) {
                $decision = 'CUENTA en recuperación (PAGO real)';
                $marker   = '<fg=green>✓</>';
                $totalRecovCounts += (float)$r->total;
            } else {
                $decision = 'NO CUENTA en recuperación (' . ($r->trans ?? 'sin transacción') . ')';
                $marker   = '<fg=yellow>~</>';
                $totalRecovNoCount += (float)$r->total;
            }

            $this->line(sprintf(
                '  %s %-35s %-28s %-12s %-25s cnt=%-4d $%s → %s',
                $marker,
                mb_substr($r->product_name, 0, 35),
                mb_substr($r->sucursal, 0, 28),
                mb_substr($r->trans ?? '(null)', 0, 12),
                mb_substr($r->concepto ?? '(null)', 0, 25),
                $r->cnt,
                number_format((float)$r->total, 2),
                $decision
            ));

            $totalRecov += (float)$r->total;
        }

        $this->line('');
        $this->line(sprintf('  Total en BD                : $%s', number_format($totalRecov, 2)));
        $this->line(sprintf('  Total CUENTA (PAGO)        : $%s', number_format($totalRecovCounts, 2)));
        $this->line(sprintf('  Total NO CUENTA            : $%s', number_format($totalRecovNoCount, 2)));
        $this->line('');

        // ── 3. SALDOS / CARTERA (fact_portfolios) ───────────────────────
        $this->info('── 3. CARTERA (fact_portfolios) ──');
        $this->line('  Regla: TODA reestructura vigente CUENTA en cartera (es saldo actual real).');
        $this->line('         Solo se excluyen sucursales no operativas.');
        $this->line('');

        $portfolios = DB::table('fact_portfolios as po')
            ->leftJoin('branches as b', 'po.branch_id', '=', 'b.id')
            ->whereIn('po.period_id', $dataIds)
            ->whereRaw('po.product_name REGEXP ?', [self::PATTERN])
            ->selectRaw('po.product_name, COALESCE(b.name,"(sin sucursal)") as sucursal, LOWER(COALESCE(b.name,"")) as sucursal_lower, COUNT(*) as cnt, SUM(po.balance) as saldo, SUM(COALESCE(po.past_due_balance,0)) as mora')
            ->groupBy('po.product_name', 'b.id', 'b.name')
            ->orderByDesc('saldo')
            ->get();

        $totalPortfolio   = 0.0;
        $totalPortfNoCount = 0.0;
        $totalPortfCounts  = 0.0;

        foreach ($portfolios as $r) {
            $isNonOp = RegionNorteFilter::isNonOperative($r->sucursal);

            if ($isNonOp) {
                $decision = 'NO CUENTA (sucursal no operativa)';
                $marker   = '<fg=red>✗</>';
                $totalPortfNoCount += (float)$r->saldo;
            } else {
                $decision = 'CUENTA en cartera (saldo vigente real)';
                $marker   = '<fg=green>✓</>';
                $totalPortfCounts += (float)$r->saldo;
            }

            $this->line(sprintf(
                '  %s %-40s %-28s cnt=%-4d saldo=$%-14s mora=$%s → %s',
                $marker,
                mb_substr($r->product_name, 0, 40),
                mb_substr($r->sucursal, 0, 28),
                $r->cnt,
                number_format((float)$r->saldo, 2),
                number_format((float)$r->mora, 2),
                $decision
            ));

            $totalPortfolio += (float)$r->saldo;
        }

        $this->line('');
        $this->line(sprintf('  Total saldo en BD          : $%s', number_format($totalPortfolio, 2)));
        $this->line(sprintf('  Total CUENTA en cartera    : $%s', number_format($totalPortfCounts, 2)));
        $this->line(sprintf('  Total excluido (no-op)     : $%s', number_format($totalPortfNoCount, 2)));
        $this->line('');

        // ── 4. Resumen ejecutivo ────────────────────────────────────────
        $this->info('── 4. Resumen ejecutivo ──');
        $this->line(sprintf('  COLOCACIÓN  — reestructura excluida (no-op+RRPP)          : -$%s', number_format($totalNoCount, 2)));
        $this->line(sprintf('  COLOCACIÓN  — reestructura a revisar (op, posible parcial) : $%s', number_format($totalOpPlace, 2)));
        $this->line(sprintf('  RECUPERACIÓN — reestructuras que SÍ cuentan (PAGO, op)    : +$%s', number_format($totalRecovCounts, 2)));
        $this->line(sprintf('  RECUPERACIÓN — reestructuras que NO cuentan               : $%s', number_format($totalRecovNoCount, 2)));
        $this->line(sprintf('  CARTERA     — reestructuras que SÍ cuentan (op)            : +$%s', number_format($totalPortfCounts, 2)));
        $this->line('');
        $this->line('  Nota: Los pagos de reestructura (PAGO) ya están incluidos en el total');
        $this->line('  de recuperación del debug-cobranza. Esta sección solo los identifica.');
        $this->line('');

        $this->info("══════════════════════════════════════════════════════════════");
        $this->line('');

        return self::SUCCESS;
    }
}
