<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditPlacementCommand extends Command
{
    protected $signature   = 'reportes:audit-placements
                                {period_id}
                                {--compare-reference : comparar contra referencia manual confirmada}
                                {--by-branch         : comparativo por sucursal}
                                {--by-origin         : totales por Origen crédito}
                                {--by-product        : totales por producto + detalle CRECE/COMADRES}
                                {--detail            : mostrar todas las secciones}';
    protected $description = 'Auditoría de Colocación/Otorgamientos: columnas, origen crédito, producto, seguro CRECE/COMADRES (informativo), comparativo por sucursal';

    // Referencia confirmada manualmente — Abril 2026 (periodo 14)
    // KPI de Colocación = bruto (Monto desembolsado). El Seguro CRECE/COMADRES es SOLO informativo, NO se resta.
    private const REF_COLOCACION = 13_351_612.00; // SUM(Monto desembolsado), DESEMBOLSO+REFINANCIAMIENTO — KPI oficial
    private const REF_SEGURO     = 11_391.72;     // Seguro CRECE/COMADRES — informativo

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
        $dataIdsSql = implode(',', $dataIds);

        $showAll = $this->option('detail');

        $this->line('');
        $this->info("════════════════════════════════════════════════════════════════");
        $this->info("  AUDITORÍA COLOCACIÓN / OTORGAMIENTOS — {$period->label} (ID {$period->id})");
        $this->info("  DataIds: [" . implode(', ', $dataIds) . "]");
        $this->info("  KPI Colocación = SUM(Monto desembolsado) | Incluye: DESEMBOLSO + REFINANCIAMIENTO");
        $this->info("  Excluye: REESTRUCTURACIÓN + UNIFICACIÓN");
        $this->info("  Seguro CRECE/COMADRES: SOLO INFORMATIVO — NO se resta del KPI de colocación.");
        $this->info("════════════════════════════════════════════════════════════════");

        // ── A) TOTALES POR COLUMNA ────────────────────────────────────────────
        $this->line('');
        $this->info('════ A. TOTALES POR COLUMNA (desde raw_payload) ════');

        $totalRows = DB::table('fact_placements')->whereIn('period_id', $dataIds)->count();
        $this->line("  Filas totales en BD: " . number_format($totalRows));
        $this->line('');

        $jsonCols = [
            'monto_desembolsado'         => 'Monto desembolsado   ← USAR como KPI de colocación',
            'efectivo_desembolsado'      => '$ Desembolsado       ← NO usar',
            'cuota_total'                => 'Cuota total          ← NO usar',
            'interes_total'              => 'Interés total crédito ← NO usar',
            'impuesto_total'             => 'Impuesto total crédito ← NO usar',
            'adeudo_total'               => 'Adeudo Total          ← NO usar',
            'seguro'                     => 'Seguro                ← informativo, NO se resta del KPI',
            'apertura'                   => 'Apertura              ← NO usar',
            'descuento_refinanciamiento' => 'Desc. refinanciamiento ← NO usar',
            'descuento_subproductos'     => 'Desc. subproductos    ← NO usar',
        ];

        $this->line(str_pad('Columna', 46) . 'Total');
        $this->line(str_repeat('─', 90));

        foreach ($jsonCols as $key => $label) {
            $val = (float) DB::selectOne(
                "SELECT SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), '$.{$key}')) AS DECIMAL(14,2))) as tot
                 FROM fact_placements WHERE period_id IN ({$dataIdsSql})"
            )?->tot ?? 0;

            $this->line(str_pad(mb_substr($label, 0, 44), 46) . '$' . number_format($val, 2));
        }

        // ── B) TOTALES POR ORIGEN CRÉDITO ────────────────────────────────────
        if ($showAll || $this->option('by-origin')) {
            $this->line('');
            $this->info('════ B. TOTALES POR ORIGEN CRÉDITO ════');

            $byOrigin = DB::selectOne(
                "SELECT
                    SUM(CASE WHEN UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload),'$.credit_origin'))) = 'DESEMBOLSO'
                        THEN amount ELSE 0 END) as desembolso,
                    SUM(CASE WHEN UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload),'$.credit_origin'))) = 'REFINANCIAMIENTO'
                        THEN amount ELSE 0 END) as refi,
                    SUM(CASE WHEN UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload),'$.credit_origin'))) LIKE 'REESTRUCTUR%'
                        THEN amount ELSE 0 END) as restr,
                    SUM(CASE WHEN UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload),'$.credit_origin'))) LIKE 'UNIFICACI%'
                        THEN amount ELSE 0 END) as unif,
                    SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload),'$.credit_origin')) IS NULL
                              OR  JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload),'$.credit_origin')) = 'null'
                        THEN amount ELSE 0 END) as sin_origen,
                    COUNT(CASE WHEN UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload),'$.credit_origin'))) = 'DESEMBOLSO'
                        THEN 1 END) as cnt_desembolso,
                    COUNT(CASE WHEN UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload),'$.credit_origin'))) = 'REFINANCIAMIENTO'
                        THEN 1 END) as cnt_refi,
                    COUNT(CASE WHEN UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload),'$.credit_origin'))) LIKE 'REESTRUCTUR%'
                        THEN 1 END) as cnt_restr,
                    COUNT(CASE WHEN UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload),'$.credit_origin'))) LIKE 'UNIFICACI%'
                        THEN 1 END) as cnt_unif
                FROM fact_placements
                WHERE period_id IN ({$dataIdsSql})"
            );

            $desembolso = (float)($byOrigin->desembolso ?? 0);
            $refi       = (float)($byOrigin->refi       ?? 0);
            $restr      = (float)($byOrigin->restr      ?? 0);
            $unif       = (float)($byOrigin->unif       ?? 0);
            $sinOrigen  = (float)($byOrigin->sin_origen ?? 0);

            $origins = [
                ['DESEMBOLSO',       $desembolso, (int)($byOrigin->cnt_desembolso ?? 0), 'INCLUIDO'],
                ['REFINANCIAMIENTO', $refi,       (int)($byOrigin->cnt_refi       ?? 0), 'INCLUIDO'],
                ['REESTRUCTURACIÓN', $restr,      (int)($byOrigin->cnt_restr      ?? 0), 'EXCLUIDO'],
                ['UNIFICACIÓN',      $unif,       (int)($byOrigin->cnt_unif       ?? 0), 'EXCLUIDO'],
                ['Sin origen',       $sinOrigen,  0,                                     'SIN ORIGEN'],
            ];

            $this->line(str_pad('Origen crédito', 22) . str_pad('Registros', 12) . str_pad('Monto', 22) . 'Estado');
            $this->line(str_repeat('─', 72));
            foreach ($origins as [$lbl, $val, $cnt, $estado]) {
                $line = str_pad($lbl, 22) . str_pad(number_format($cnt), 12) . str_pad('$' . number_format($val, 2), 22) . $estado;
                if ($estado === 'INCLUIDO') $this->info("  {$line}");
                elseif ($estado === 'EXCLUIDO') $this->warn("  {$line}");
                else $this->line("  {$line}");
            }

            $this->line('');
            $this->info('  ✓ REESTRUCTURACIÓN y UNIFICACIÓN quedan excluidas del KPI de colocación.');
        }

        // ── Universo base (ya excluidos REESTRUCTURACIÓN/UNIFICACIÓN) ─────────
        $includedFilter = "UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), '$.credit_origin'))) IN ('DESEMBOLSO', 'REFINANCIAMIENTO')";

        $colocacion = (float) DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereRaw($includedFilter)
            ->sum('amount');

        $seguroCreceComadres = (float) DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereRaw($includedFilter)
            ->whereRaw("UPPER(COALESCE(product_name,'')) LIKE '%CRECE%' OR UPPER(COALESCE(product_name,'')) LIKE '%COMADRES%'")
            ->sum(DB::raw("CAST(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), '$.seguro')) AS DECIMAL(14,2))"));

        $cntCreceComadres = (int) DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereRaw($includedFilter)
            ->whereRaw("UPPER(COALESCE(product_name,'')) LIKE '%CRECE%' OR UPPER(COALESCE(product_name,'')) LIKE '%COMADRES%'")
            ->count();

        // ── C) TOTALES POR PRODUCTO ───────────────────────────────────────────
        if ($showAll || $this->option('by-product')) {
            $this->line('');
            $this->info('════ C. TOTALES POR PRODUCTO (universo: DESEMBOLSO + REFINANCIAMIENTO) ════');

            $byProduct = DB::table('fact_placements')
                ->whereIn('period_id', $dataIds)
                ->whereRaw($includedFilter)
                ->selectRaw("
                    CASE
                        WHEN UPPER(COALESCE(product_name,'')) LIKE '%CRECE%'    THEN 'CRECE'
                        WHEN UPPER(COALESCE(product_name,'')) LIKE '%COMADRES%' THEN 'COMADRES'
                        WHEN UPPER(COALESCE(product_name,'')) = 'I20'  THEN 'I20'
                        WHEN UPPER(COALESCE(product_name,'')) = 'I30'  THEN 'I30'
                        WHEN UPPER(COALESCE(product_name,'')) = 'I60'  THEN 'I60'
                        WHEN UPPER(COALESCE(product_name,'')) = 'S12'  THEN 'S12'
                        WHEN UPPER(COALESCE(product_name,'')) = 'S16'  THEN 'S16'
                        WHEN UPPER(COALESCE(product_name,'')) = 'S20'  THEN 'S20'
                        WHEN UPPER(COALESCE(product_name,'')) = 'S24'  THEN 'S24'
                        WHEN UPPER(COALESCE(product_name,'')) = 'A LA MEDIDA' THEN 'A LA MEDIDA'
                        ELSE 'OTROS'
                    END as grupo,
                    COUNT(*) as cnt,
                    SUM(amount) as monto,
                    SUM(CASE
                        WHEN UPPER(COALESCE(product_name,'')) LIKE '%CRECE%' OR UPPER(COALESCE(product_name,'')) LIKE '%COMADRES%'
                        THEN COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), '$.seguro')) AS DECIMAL(14,2)), 0)
                        ELSE 0
                    END) as seguro
                ")
                ->groupBy('grupo')
                ->orderByDesc('monto')
                ->get();

            $this->line(str_pad('Producto', 16) . str_pad('Cttos', 8) . str_pad('Colocación (KPI)', 22) . 'Seguro (informativo)');
            $this->line(str_repeat('─', 70));
            $totMonto = 0; $totSeguro = 0;
            foreach ($byProduct as $p) {
                $monto  = (float) $p->monto;
                $seguro = (float) $p->seguro;
                $totMonto += $monto;
                $totSeguro += $seguro;
                $this->line(
                    str_pad($p->grupo, 16) .
                    str_pad(number_format($p->cnt), 8) .
                    str_pad('$' . number_format($monto, 2), 22) .
                    ($seguro > 0 ? '$' . number_format($seguro, 2) : '—')
                );
            }
            $this->line(str_repeat('─', 70));
            $this->line(str_pad('TOTAL', 24) . str_pad('$' . number_format($totMonto, 2), 22) . '$' . number_format($totSeguro, 2));

            // ── D) Detalle CRECE / COMADRES por sucursal+producto ───────────
            $this->line('');
            $this->info('════ D. DETALLE CRECE / COMADRES POR SUCURSAL Y PRODUCTO (seguro informativo) ════');

            $detalle = DB::table('fact_placements as p')
                ->leftJoin('branches as b', 'p.branch_id', '=', 'b.id')
                ->whereIn('p.period_id', $dataIds)
                ->whereRaw($includedFilter)
                ->whereRaw("UPPER(COALESCE(p.product_name,'')) LIKE '%CRECE%' OR UPPER(COALESCE(p.product_name,'')) LIKE '%COMADRES%'")
                ->selectRaw("
                    UPPER(COALESCE(b.name,'SIN SUCURSAL')) as sucursal,
                    p.product_name as producto,
                    COUNT(*) as cnt,
                    SUM(p.amount) as monto,
                    SUM(COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(p.raw_payload), '$.seguro')) AS DECIMAL(14,2)), 0)) as seguro
                ")
                ->groupBy('sucursal', 'p.product_name')
                ->orderBy('sucursal')
                ->orderBy('p.product_name')
                ->get();

            $this->line(str_pad('Sucursal', 22) . str_pad('Producto', 18) . str_pad('Colocación (KPI)', 18) . str_pad('Seguro (info)', 14) . 'Reg.');
            $this->line(str_repeat('─', 90));
            foreach ($detalle as $d) {
                $monto = (float) $d->monto;
                $seg   = (float) $d->seguro;
                $this->line(
                    str_pad(mb_substr($d->sucursal, 0, 20), 22) .
                    str_pad(mb_substr($d->producto, 0, 16), 18) .
                    str_pad('$' . number_format($monto, 2), 18) .
                    str_pad('$' . number_format($seg, 2), 14) .
                    (string) $d->cnt
                );
            }
        }

        // ── E) COMPARATIVO POR SUCURSAL (todos los productos) ─────────────────
        if ($showAll || $this->option('by-branch')) {
            $this->line('');
            $this->info('════ E. COLOCACIÓN POR SUCURSAL (KPI, seguro mostrado solo informativo) ════');

            $byBranch = DB::table('fact_placements as p')
                ->leftJoin('branches as b', 'p.branch_id', '=', 'b.id')
                ->whereIn('p.period_id', $dataIds)
                ->whereRaw($includedFilter)
                ->selectRaw("
                    UPPER(COALESCE(b.name,'SIN SUCURSAL')) as sucursal,
                    COUNT(*) as cnt,
                    SUM(p.amount) as monto,
                    SUM(CASE
                        WHEN UPPER(COALESCE(p.product_name,'')) LIKE '%CRECE%' OR UPPER(COALESCE(p.product_name,'')) LIKE '%COMADRES%'
                        THEN COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(p.raw_payload), '$.seguro')) AS DECIMAL(14,2)), 0)
                        ELSE 0
                    END) as seguro
                ")
                ->groupBy('sucursal')
                ->orderByDesc('monto')
                ->get();

            $this->line(str_pad('Sucursal', 22) . str_pad('Colocación (KPI)', 20) . str_pad('Seguro (informativo)', 22) . 'Reg.');
            $this->line(str_repeat('─', 80));
            $totMonto = 0; $totSeguro = 0;
            foreach ($byBranch as $b) {
                $monto = (float) $b->monto;
                $seg   = (float) $b->seguro;
                $totMonto += $monto;
                $totSeguro += $seg;
                $this->line(
                    str_pad(mb_substr($b->sucursal, 0, 20), 22) .
                    str_pad('$' . number_format($monto, 2), 20) .
                    str_pad(($seg > 0 ? '$' . number_format($seg, 2) : '—'), 22) .
                    (string) $b->cnt
                );
            }
            $this->line(str_repeat('─', 80));
            $this->line(
                str_pad('TOTAL', 22) .
                str_pad('$' . number_format($totMonto, 2), 20) .
                '$' . number_format($totSeguro, 2)
            );
        }

        // ── F) TOTAL EXCLUIDO (REESTRUCTURACIÓN + UNIFICACIÓN) ────────────────
        $this->line('');
        $this->info('════ F. TOTAL EXCLUIDO (REESTRUCTURACIÓN + UNIFICACIÓN) ════');

        $totalExcluido = (float) DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), '$.credit_origin'))) IN ('REESTRUCTURACIÓN', 'REESTRUCTURACION', 'UNIFICACIÓN', 'UNIFICACION')")
            ->sum('amount');
        $this->line("  Total excluido: $" . number_format($totalExcluido, 2));

        // ── G) RESULTADO FINAL ──────────────────────────────────────────────────
        $this->line('');
        $this->info('════ G. RESULTADO FINAL ════');

        $rows = [
            ['Colocación (KPI oficial, dashboard/Excel/PDF/utilidad)', $colocacion, self::REF_COLOCACION],
            ['Seguro CRECE/COMADRES (informativo, NO se resta)',       $seguroCreceComadres, self::REF_SEGURO],
        ];

        $this->line(str_pad('Concepto', 56) . str_pad('Sistema', 20) . str_pad('Referencia', 20) . 'Diferencia');
        $this->line(str_repeat('─', 110));
        foreach ($rows as [$label, $sys, $ref]) {
            $diff = $sys - $ref;
            $sign = $diff >= 0 ? '+' : '';
            $match = abs($diff) < 1 ? '✓ EXACTO' : (abs($diff) < 500 ? '≈ CERCANO' : '✗ DIFERENCIA');
            $line = str_pad($label, 56) . str_pad('$' . number_format($sys, 2), 20) . str_pad('$' . number_format($ref, 2), 20) . "{$sign}\$" . number_format(abs($diff), 2) . "  {$match}";
            if (abs($diff) < 1) $this->info("  {$line}");
            else $this->warn("  {$line}");
        }
        $this->line('');
        $this->line("  Registros CRECE/COMADRES (solo para el dato informativo de seguro): " . number_format($cntCreceComadres) . " (ref: 76)");

        // ── RESULTADO FINAL — CHECKLIST ────────────────────────────────────────
        $this->line('');
        $this->info('════ CHECKLIST FINAL ════');
        $this->printCheck(true, 'Base usada: Monto desembolsado');
        $this->printCheck(true, 'Columnas NO usadas: Cuota, Cuota total, $ Desembolsado, Adeudo Total, Interés, Impuesto, Apertura, Descuentos');
        $this->printCheck(true, 'Incluye solo DESEMBOLSO + REFINANCIAMIENTO');
        $this->printCheck(true, 'Excluye REESTRUCTURACIÓN + UNIFICACIÓN');
        $this->printCheck(true, 'Seguro CRECE/COMADRES NO se resta del KPI — solo se muestra informativo');
        $this->printCheck(abs($colocacion - self::REF_COLOCACION) < 1, 'Colocación (KPI) = $' . number_format(self::REF_COLOCACION, 2));
        $this->printCheck(abs($seguroCreceComadres - self::REF_SEGURO) < 1, 'Seguro CRECE/COMADRES informativo = $' . number_format(self::REF_SEGURO, 2));

        if (abs($colocacion - self::REF_COLOCACION) >= 1) {
            $this->line('');
            $this->warn('  → Revisar sección D (detalle CRECE/COMADRES) y sección E (por sucursal) para ubicar el origen de la diferencia.');
        }

        return 0;
    }

    private function printCheck(bool $ok, string $label): void
    {
        $icon = $ok ? '✓' : '✗';
        $msg  = "  [{$icon}] {$label}";
        if ($ok) $this->info($msg);
        else     $this->warn($msg);
    }
}
