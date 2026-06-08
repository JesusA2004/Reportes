<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditPlacementCommand extends Command
{
    protected $signature   = 'reportes:audit-placements
                                {period_id}
                                {--detail : conciliación monto53 vs desemb54 vs referencia}';
    protected $description = 'Auditoría completa de colocación/ministraciones: columnas, monto autorizado vs desembolso';

    public function handle(): int
    {
        $period = Period::find($this->argument('period_id'));
        if (!$period) {
            $this->error("Periodo {$this->argument('period_id')} no encontrado.");
            return 1;
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(
            empty($weeklyIds) ? [] : $weeklyIds,
            [$period->id]
        )));

        $this->info("════════════════════════════════════════════════════════════");
        $this->info("  AUDITORÍA COLOCACIÓN — {$period->label} (ID {$period->id})");
        $this->info("  DataIds: [" . implode(', ', $dataIds) . "]");
        $this->info("════════════════════════════════════════════════════════════");

        $total   = DB::table('fact_placements')->whereIn('period_id', $dataIds)->count();
        $amount  = DB::table('fact_placements')->whereIn('period_id', $dataIds)->sum('amount');

        $this->line('');
        $this->info('── TOTALES GENERALES ──');
        $this->line("  Filas totales: " . number_format($total));
        $this->line("  Monto total:   $" . number_format((float)$amount, 2));

        // ── Por producto ─────────────────────────────────────────────────────
        $this->line('');
        $this->info('── POR PRODUCTO ──');

        $byProd = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->select('product_name',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(amount) as total'))
            ->groupBy('product_name')
            ->orderByDesc('total')
            ->get();

        $operPatterns = ['RECURSOS PROPIOS', 'REESTRUCTURA', 'UNIFICACION', 'MIGRACION', 'SEGURO', 'CRECE'];

        $this->line(str_pad('Producto', 40) . str_pad('Filas', 8) .
                    str_pad('Monto', 20) . 'Clasificación');
        $this->line(str_repeat('-', 85));
        foreach ($byProd as $p) {
            $name = $p->product_name ?? 'NULL/Sin producto';
            $upper = strtoupper($name);
            $clasif = 'OPERATIVO';
            if (str_contains($upper, 'RECURSOS PROPIOS'))   $clasif = 'FONDEO (excluido)';
            elseif (str_contains($upper, 'REESTRUCTURA'))    $clasif = 'REESTRUCTURA (excluido)';
            elseif (str_contains($upper, 'UNIFICACION'))     $clasif = 'UNIFICACION (excluido)';
            elseif (str_contains($upper, 'MIGRACION'))       $clasif = 'MIGRACIÓN (excluido)';
            elseif (str_contains($upper, 'SEGURO'))          $clasif = 'SEGURO (excluido)';
            elseif (str_contains($upper, 'CRECE'))           $clasif = 'CRECE (OPERATIVO)';
            elseif ($name === 'NULL/Sin producto')           $clasif = 'SIN PRODUCTO';

            $this->line(
                str_pad(mb_substr($name, 0, 38), 40) .
                str_pad(number_format($p->cnt), 8) .
                str_pad('$' . number_format((float)$p->total, 0), 20) .
                $clasif
            );
        }

        // ── Por sucursal ─────────────────────────────────────────────────────
        $this->line('');
        $this->info('── POR SUCURSAL ──');

        $byBranch = DB::table('fact_placements as fp')
            ->leftJoin('branches as b', 'fp.branch_id', '=', 'b.id')
            ->whereIn('fp.period_id', $dataIds)
            ->select(DB::raw('COALESCE(b.name,"Sin sucursal") as sucursal'),
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(fp.amount) as total'))
            ->groupBy('fp.branch_id', 'b.name')
            ->orderByDesc('total')
            ->get();

        foreach ($byBranch as $b) {
            $this->line("  " . str_pad(mb_substr($b->sucursal, 0, 30), 32) .
                        str_pad(number_format($b->cnt), 8) .
                        '$' . number_format((float)$b->total, 2));
        }

        // ── Registros excluidos del reporte final ────────────────────────────
        $this->line('');
        $this->info('── REGISTROS EXCLUIDOS DEL REPORTE (no aparecen como colocación neta) ──');

        $excluded = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("product_name REGEXP 'RECURSOS PROPIOS|REESTRUCTURA|UNIFICACION|MIGRACION|SEGURO'")
            ->select('product_name',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(amount) as total'))
            ->groupBy('product_name')
            ->get();

        if ($excluded->isEmpty()) {
            $this->line("  No hay registros excluidos (solo RECURSOS PROPIOS/reestructuras).");
        } else {
            foreach ($excluded as $e) {
                $this->warn("  ✗ EXCLUIDO: {$e->product_name}: {$e->cnt} ops, $" . number_format((float)$e->total, 2));
            }
        }

        // ── RECURSOS PROPIOS diagnóstico ──────────────────────────────────────
        $rrpp = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("product_name REGEXP 'RECURSOS PROPIOS'")
            ->select('product_name', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(amount) as total'))
            ->groupBy('product_name')
            ->get();

        $this->line('');
        $this->info('── DIAGNÓSTICO RECURSOS PROPIOS ──');
        if ($rrpp->isEmpty()) {
            $this->line("  ✓ RECURSOS PROPIOS no encontrado en colocación.");
        } else {
            foreach ($rrpp as $r) {
                $this->warn("  ! {$r->product_name}: {$r->cnt} ops, $" . number_format((float)$r->total, 2));
            }
            $this->line("  → RECURSOS PROPIOS se excluye correctamente del reporte de colocación neta.");
            $this->line("  → Ver sección 'Préstamos intersucursales' / fondeo para este dato.");
        }

        // ── CRECE diagnóstico ─────────────────────────────────────────────────
        $creceCount = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("UPPER(COALESCE(product_name,'')) REGEXP 'CRECE'")
            ->count();

        $this->line('');
        $this->info('── DIAGNÓSTICO CRECE EN COLOCACIÓN ──');
        if ($creceCount === 0) {
            $this->line("  ✓ No hay colocación CRECE en este periodo.");
        } else {
            $this->info("  ✓ CRECE encontrado en colocación: {$creceCount} filas — correctamente clasificado como OPERATIVO");
        }

        // ── Filas sin sucursal ────────────────────────────────────────────────
        $noBranch = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereNull('branch_id')
            ->count();
        if ($noBranch > 0) {
            $this->line('');
            $this->warn("  ⚠ {$noBranch} filas sin branch_id — sucursal no resuelta por código de promotor");
        }

        if ($this->option('detail')) {
            $this->showMontoConciliation($dataIds);
        }

        return 0;
    }

    private function showMontoConciliation(array $dataIds): void
    {
        $this->line('');
        $this->info('════════════════════════════════════════════════════════════════');
        $this->info('  CONCILIACIÓN: amount_desemb54 vs amount_monto53 (raw_payload)');
        $this->info('  amount (BD)  = desembolso neto al cliente (lo que realmente recibió)');
        $this->info('  monto53      = monto autorizado/financiado (face value del crédito)');
        $this->info('════════════════════════════════════════════════════════════════');

        $result = DB::selectOne("
            SELECT
                COUNT(*) as cnt,
                SUM(amount) as sum_desemb,
                SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), '$.amount_monto53')) AS DECIMAL(14,2))) as sum_monto53,
                SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), '$.is_refi')) = 'true' THEN 1 ELSE 0 END) as refi_cnt,
                SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), '$.is_refi')) = 'true'
                    THEN amount ELSE 0 END) as refi_desemb,
                SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), '$.is_refi')) = 'true'
                    THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), '$.amount_monto53')) AS DECIMAL(14,2)) ELSE 0 END) as refi_monto53
            FROM fact_placements
            WHERE period_id IN (" . implode(',', $dataIds) . ")
        ");

        $sumDesemb  = (float)($result->sum_desemb ?? 0);
        $sumMonto53 = (float)($result->sum_monto53 ?? 0);
        $refiDesemb = (float)($result->refi_desemb ?? 0);
        $refiMonto  = (float)($result->refi_monto53 ?? 0);
        $refiCnt    = (int)($result->refi_cnt ?? 0);

        $this->line('');
        $this->info('════ A. TOTALES POR COLUMNA ════');
        $this->line(str_pad('Columna', 45) . str_pad('Total', 18) . 'Descripción');
        $this->line(str_repeat('─', 90));
        $this->line(str_pad('amount (desembolso neto, BD)', 45) . str_pad('$' . number_format($sumDesemb, 2), 18) . '← lo que usa el sistema ahora');
        $this->line(str_pad('amount_monto53 (raw_payload, monto autorizado)', 45) . str_pad('$' . number_format($sumMonto53, 2), 18) . '← monto face value del crédito');
        $this->line(str_pad('Diferencia (monto53 − desemb)', 45) . '$' . number_format($sumMonto53 - $sumDesemb, 2));

        $this->line('');
        $this->info('════ B. REFINANCIAMIENTOS (is_refi=true) ════');
        $this->line("  Registros refi: {$refiCnt} de {$result->cnt}");
        $this->line("  Desembolso neto refi: $" . number_format($refiDesemb, 2) . " (reducido: antigua deuda ya contada)");
        $this->line("  Monto53 refi:         $" . number_format($refiMonto, 2)  . " (face value nuevo crédito)");
        $this->line("  Diferencia por refi:  $" . number_format($refiMonto - $refiDesemb, 2) . " ← explica la brecha");

        $this->line('');
        $this->info('════ C. CONCILIACIÓN VS REFERENCIA ════');
        $refOtorg   = 14_538_964.00;

        // monto53 excluding reestructuras/unificaciones
        $monto53SinRestr = (float)DB::selectOne("
            SELECT SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), '$.amount_monto53')) AS DECIMAL(14,2))) as tot
            FROM fact_placements
            WHERE period_id IN (" . implode(',', $dataIds) . ")
            AND (product_name IS NULL OR product_name NOT REGEXP 'REESTRUCTURA|UNIFICACION|RECURSOS PROPIOS')
        ")->tot ?? 0;

        $desembSinRestr = (float)DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->where(function ($q) { $q->whereNull('product_name')->orWhereRaw("product_name NOT REGEXP ?", ['REESTRUCTURA|UNIFICACION|RECURSOS PROPIOS']); })
            ->sum('amount');

        $rows = [
            ['ACTUAL: amount/desemb54 (con restr.)',          $sumDesemb],
            ['ACTUAL: amount/desemb54 (sin restr.)',          $desembSinRestr],
            ['monto53 (con reestructuras)',                   $sumMonto53],
            ['monto53 (sin reestructuras/unificaciones)',     $monto53SinRestr],
            ['REFERENCIA abril 2026',                        $refOtorg],
        ];

        $this->line(str_pad('Enfoque', 48) . str_pad('Monto', 18) . str_pad('Diff vs Ref', 16) . 'Match');
        $this->line(str_repeat('─', 90));
        foreach ($rows as [$lbl, $val]) {
            $diff  = $val - $refOtorg;
            $sign  = $diff >= 0 ? '+' : '';
            $match = abs($diff) < 100 ? '✓ EXACTO' : ($diff < 5000 ? '≈ CERCANO' : '');
            $this->line(
                str_pad($lbl, 48) .
                str_pad('$' . number_format($val, 2), 18) .
                str_pad($sign . '$' . number_format($diff, 2), 16) .
                $match
            );
        }

        $this->line('');
        if (abs($monto53SinRestr - $refOtorg) < 100) {
            $this->info('  ✓ CONCLUSIÓN: monto53 sin reestructuras = $' . number_format($monto53SinRestr, 2) . ' = EXACTO con referencia.');
            $this->info('  → El sistema debe usar amount_monto53 (monto autorizado) en vez de amount (desembolso).');
            $this->info('  → Diferencia causada por refinanciamientos donde desemb54 = monto neto al cliente.');
        } else {
            $this->warn('  ⚠ Ningún enfoque coincide exactamente con la referencia. Verificar.');
        }
    }
}
