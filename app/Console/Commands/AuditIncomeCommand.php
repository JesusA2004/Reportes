<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\BranchResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Detailed income/recovery audit with desglose by component, product, and branch.
 *
 * Shows exactly: fuente, tabla, columna, registros, monto — sin estimaciones.
 */
class AuditIncomeCommand extends Command
{
    protected $signature = 'reportes:audit-income
                                {period_id}
                                {--product= : filter by product keyword}
                                {--by-product : show breakdown by product}
                                {--by-branch : show breakdown by branch (nueva fórmula seguros)}
                                {--direction-comercial : per-branch conciliation table vs Dirección Comercial reference}
                                {--detail : full conciliation: por tipo transacción, desglose seguros, comparativo enfoques}
                                {--compare-reference : deep audit against reference $18,792,939.79 with all combinations}
                                {--descuento-breakdown : desglose DESCUENTO por sucursal/producto/operación}';
    protected $description = 'Auditoría detallada de ingresos/recuperación por componente, producto y sucursal';

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
            empty($weeklyIds) ? [] : $weeklyIds, [$period->id]
        )));

        $filterProd = strtoupper(trim($this->option('product') ?? ''));

        $this->info("════════════════════════════════════════════════════════════════");
        $this->info("  AUDITORÍA INGRESOS / RECUPERACIÓN — {$period->label} (ID {$period->id})");
        $this->info("  Fuente principal: fact_recoveries (Ingresos/Cobranza Lendus)");
        $this->info("  Filtro producto: " . ($filterProd ?: 'todos'));
        $this->info("════════════════════════════════════════════════════════════════");

        $q = DB::table('fact_recoveries')->whereIn('period_id', $dataIds);
        if ($filterProd) {
            $q->whereRaw("UPPER(COALESCE(product_name,'')) LIKE ?", ["%{$filterProd}%"]);
        }

        // ── Resumen desglose seguros ──────────────────────────────────────────
        $this->line('');
        $this->info('════ DESGLOSE RECUPERACIÓN / COBRANZA ════');
        $this->line('  Regla: Recuperación = SUM(total_amount) − Seguros/Coberturas (is_savehearts=1, completo,');
        $this->line('  incl. CRECE 30%/70%) − Condonaciones (transaction=CONDONACION, cubre Unificación de Cartera).');
        $this->line('');

        $tot = (clone $q)->selectRaw("
            COUNT(*) as filas,
            SUM(total_amount) as bruta,
            SUM(CASE WHEN is_savehearts = 1 AND savehearts_crece_share = 0 THEN total_amount ELSE 0 END) as seguro_excluido,
            SUM(CASE WHEN is_savehearts = 1 AND savehearts_crece_share > 0 THEN total_amount ELSE 0 END) as crece_bruto,
            SUM(COALESCE(savehearts_crece_share, 0)) as crece_reconocido,
            SUM(CASE WHEN transaction = 'CONDONACION' THEN total_amount ELSE 0 END) as condonacion_excluida,
            SUM(CASE
                WHEN is_savehearts = 1 THEN 0
                WHEN transaction = 'CONDONACION' THEN 0
                WHEN UPPER(COALESCE(concept,'')) LIKE '%COBERTURA%' OR UPPER(COALESCE(operation,'')) LIKE '%COBERTURA%'
                  OR UPPER(COALESCE(concept,'')) LIKE '%SEGURO%' OR UPPER(COALESCE(operation,'')) LIKE '%SEGURO%' THEN 0
                ELSE total_amount
            END) as recuperacion_total,
            SUM(CASE WHEN is_savehearts THEN 1 ELSE 0 END) as savehearts_filas
        ")->first();

        $bruta             = (float)($tot->bruta ?? 0);
        $seguroExcluido    = (float)($tot->seguro_excluido ?? 0);
        $creceBruto        = (float)($tot->crece_bruto ?? 0);
        $creceReconocido   = (float)($tot->crece_reconocido ?? 0);
        $creceNoReconocido = max(0.0, $creceBruto - $creceReconocido);
        $condonacion       = (float)($tot->condonacion_excluida ?? 0);
        $recuperacionTotal = (float)($tot->recuperacion_total ?? 0);

        $ref  = 18_324_971.76;
        $diff = $recuperacionTotal - $ref;
        $sign = $diff >= 0 ? '+' : '';

        $this->line(str_pad('Concepto', 46) . str_pad('Monto', 20) . 'Clasificación');
        $this->line(str_repeat('─', 90));
        $this->line(str_pad('Recuperación bruta (total archivo)', 46) . str_pad('$' . number_format($bruta, 2), 20) . 'SUM(total_amount) sin filtros');
        $this->warn(str_pad('(-) Seguros/Coberturas (Savehearts/Comadres)', 46) . str_pad('-$' . number_format($seguroExcluido, 2), 20) . 'is_savehearts=1, share=0');
        $this->warn(str_pad('(-) Seguro CRECE bruto (100%, no se reconoce aquí)', 46) . str_pad('-$' . number_format($creceBruto, 2), 20) . 'is_savehearts=1, share>0');
        $this->line(str_pad('    (30% reconocido — informativo, no en este KPI)', 46) . str_pad('$' . number_format($creceReconocido, 2), 20) . 'savehearts_crece_share');
        $this->line(str_pad('    (70% puente — informativo)', 46) . str_pad('$' . number_format($creceNoReconocido, 2), 20) . 'bruto - share');
        $this->warn(str_pad('(-) Condonaciones (incl. Unificación de Cartera)', 46) . str_pad('-$' . number_format($condonacion, 2), 20) . "transaction='CONDONACION'");
        $this->line(str_repeat('═', 90));

        $totalLine = str_pad('RECUPERACIÓN TOTAL', 46) . '$' . number_format($recuperacionTotal, 2);
        if (abs($diff) < 1.0) {
            $this->info($totalLine . '  ✓ EXACTO vs target $' . number_format($ref, 2));
        } else {
            $this->warn($totalLine . '  Diff vs target: ' . $sign . '$' . number_format($diff, 2));
        }

        $this->line('');
        $this->line("  Filas totales: " . number_format((int)($tot->filas ?? 0)) . " | is_savehearts=true: " . number_format((int)($tot->savehearts_filas ?? 0)));
        $this->line("  Target: \$" . number_format($ref, 2) . " | Diff: {$sign}\$" . number_format(abs($diff), 2));

        // ── Savehearts detail ─────────────────────────────────────────────────
        $this->line('');
        $this->info('── OPERACIONES ESPECIALES (Savehearts / Seguros) ──');
        $byOp = (clone $q)
            ->select('operation', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(total_amount) as total'))
            ->groupBy('operation')
            ->orderByDesc('total')
            ->limit(15)
            ->get();

        $this->line(str_pad('Operación', 35) . str_pad('Regs', 8) . str_pad('Total', 18) . 'Savehearts?');
        $this->line(str_repeat('─', 75));
        foreach ($byOp as $op) {
            $opLower = strtolower(trim($op->operation ?? ''));
            $isSh    = in_array($opLower, ['seguro', 'seguros', 'poliza', 'polizas', 'cobertura', 'cobertura savehearts']);
            $marker  = $isSh ? '← SAVEHEARTS candidato' : '';
            $this->line(
                str_pad(mb_substr($op->operation ?? 'NULL', 0, 33), 35) .
                str_pad(number_format($op->cnt), 8) .
                str_pad('$' . number_format((float)$op->total, 0), 18) .
                $marker
            );
        }

        // ── Por producto ─────────────────────────────────────────────────────
        if ($this->option('by-product')) {
            $this->line('');
            $this->info('════ DESGLOSE POR PRODUCTO ════');

            $byProd = (clone $q)
                ->select(
                    'product_name',
                    DB::raw('COUNT(*) as cnt'),
                    DB::raw('SUM(capital) as capital'),
                    DB::raw('SUM(interest) as interes'),
                    DB::raw('SUM(tax) as impuesto'),
                    DB::raw('SUM(charges) as cargos'),
                    DB::raw('SUM(savehearts_crece_share) as polizas30'),
                    DB::raw('SUM(total_amount) as total')
                )
                ->groupBy('product_name')
                ->orderByDesc('total')
                ->get();

            $this->line(str_pad('Producto', 32) . str_pad('Regs', 7) . str_pad('Capital', 16) .
                        str_pad('Interés', 16) . str_pad('Impuesto', 13) . str_pad('Cargos', 13) .
                        str_pad('Pólizas30%', 13) . 'Total');
            $this->line(str_repeat('─', 120));

            foreach ($byProd as $p) {
                $this->line(
                    str_pad(mb_substr($p->product_name ?? 'NULL', 0, 30), 32) .
                    str_pad(number_format($p->cnt), 7) .
                    str_pad('$' . number_format((float)$p->capital, 0), 16) .
                    str_pad('$' . number_format((float)$p->interes, 0), 16) .
                    str_pad('$' . number_format((float)$p->impuesto, 0), 13) .
                    str_pad('$' . number_format((float)$p->cargos, 0), 13) .
                    str_pad('$' . number_format((float)$p->polizas30, 0), 13) .
                    '$' . number_format((float)$p->total, 0)
                );
            }

            $totProd = (clone $q)->selectRaw('SUM(capital) as c, SUM(interest) as i, SUM(tax) as t, SUM(charges) as ch, SUM(total_amount) as tot')->first();
            $this->line(str_repeat('─', 120));
            $this->line(
                str_pad('TOTAL', 39) .
                str_pad('$' . number_format((float)$totProd->c, 0), 16) .
                str_pad('$' . number_format((float)$totProd->i, 0), 16) .
                str_pad('$' . number_format((float)$totProd->t, 0), 13) .
                str_pad('$' . number_format((float)$totProd->ch, 0), 13) .
                str_pad('$' . number_format($polizas30, 0), 13) .
                '$' . number_format((float)$totProd->tot, 0)
            );
        }

        // ── Por sucursal (filtrado a 13 sucursales operativas) ───────────────
        if ($this->option('by-branch')) {
            $this->line('');
            $this->info('════ DESGLOSE POR SUCURSAL (nueva fórmula seguros) ════');

            $resolver    = app(BranchResolverService::class);
            $allBranches = DB::table('branches')->get();
            $operativeMap = [];
            foreach ($allBranches as $b) {
                $real = $resolver->resolveRealBranchFromRoute($b->name);
                if ($real && $resolver->isSheetBranch($real)) {
                    $operativeMap[(int) $b->id] = $real;
                }
            }
            $operativeIds = array_keys($operativeMap);

            $recSql = "SUM(CASE
                WHEN is_savehearts = 1 THEN 0
                WHEN transaction = 'CONDONACION' THEN 0
                WHEN UPPER(COALESCE(concept,'')) LIKE '%COBERTURA%' OR UPPER(COALESCE(operation,'')) LIKE '%COBERTURA%'
                  OR UPPER(COALESCE(concept,'')) LIKE '%SEGURO%' OR UPPER(COALESCE(operation,'')) LIKE '%SEGURO%' THEN 0
                ELSE total_amount
            END) as recovery";

            $byBranchRows = DB::table('fact_recoveries as fr')
                ->leftJoin('branches as b', 'fr.branch_id', '=', 'b.id')
                ->whereIn('fr.period_id', $dataIds)
                ->whereIn('fr.branch_id', $operativeIds)
                ->when($filterProd, fn($q) => $q->whereRaw("UPPER(COALESCE(fr.product_name,'')) LIKE ?", ["%{$filterProd}%"]))
                ->selectRaw("b.name as branch_name, COUNT(*) as cnt, SUM(fr.total_amount) as bruto, {$recSql}")
                ->groupBy('fr.branch_id', 'b.name')
                ->orderBy('b.name')
                ->get();

            $byCity = [];
            foreach ($byBranchRows as $br) {
                $city = $resolver->resolveRealBranchFromRoute($br->branch_name ?? '') ?? $br->branch_name ?? 'Desconocida';
                if (!isset($byCity[$city])) {
                    $byCity[$city] = ['cnt' => 0, 'bruto' => 0.0, 'recovery' => 0.0];
                }
                $byCity[$city]['cnt']      += $br->cnt;
                $byCity[$city]['bruto']    += (float) $br->bruto;
                $byCity[$city]['recovery'] += (float) $br->recovery;
            }
            uasort($byCity, fn($a, $b) => $b['recovery'] <=> $a['recovery']);

            $this->line(str_pad('Ciudad', 26) . str_pad('Regs', 8) . str_pad('Bruta', 20) . 'Recuperación final');
            $this->line(str_repeat('─', 80));

            $grandTotal = 0.0;
            foreach ($byCity as $city => $d) {
                $this->line(
                    str_pad(mb_substr($city, 0, 24), 26) .
                    str_pad(number_format($d['cnt']), 8) .
                    str_pad('$' . number_format($d['bruto'], 2), 20) .
                    '$' . number_format($d['recovery'], 2)
                );
                $grandTotal += $d['recovery'];
            }
            $this->line(str_repeat('─', 80));
            $this->info(str_pad('TOTAL (sucursales operativas)', 34) . str_pad('', 20) . '$' . number_format($grandTotal, 2));
        }

        if ($this->option('direction-comercial')) {
            $this->showDireccionComercial($dataIds, $period->label);
        }

        if ($this->option('detail')) {
            $this->showDetailConciliation($dataIds, $period->label);
        }

        if ($this->option('compare-reference')) {
            $this->showCompareReference($dataIds, $period->label);
        }

        if ($this->option('descuento-breakdown')) {
            $this->showDescuentoBreakdown($dataIds);
        }

        return 0;
    }

    private function showCompareReference(array $dataIds, string $label): void
    {
        $ref = 18_792_939.79;

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA PROFUNDA INGRESOS — {$label}");
        $this->info('  Referencia: $' . number_format($ref, 2) . ' (validada Excel)');
        $this->info('════════════════════════════════════════════════════════════════════════');

        // ── A. Por tipo de transacción ────────────────────────────────────────
        $this->line('');
        $this->info('════ A. TOTALES POR TIPO DE TRANSACCIÓN (raw_payload.transaction) ════');

        $byTxn = DB::select("
            SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')),'SIN_TXN') as txn,
                   COUNT(*) as cnt,
                   SUM(capital) as cap,
                   SUM(interest) as interes,
                   SUM(tax) as impuesto,
                   SUM(charges) as cargos,
                   SUM(charges_due) as cargos_due,
                   SUM(total_amount) as tot
            FROM fact_recoveries
            WHERE period_id IN (" . implode(',', $dataIds) . ")
            GROUP BY txn ORDER BY tot DESC
        ");

        $this->line(str_pad('Transacción', 16) . str_pad('Regs', 8) . str_pad('Capital', 16) .
                    str_pad('Interés', 14) . str_pad('Impuesto', 13) . str_pad('Cargos', 13) .
                    str_pad('Total', 17) . 'Descripción');
        $this->line(str_repeat('─', 110));

        $txnDesc = [
            'PAGO'        => 'Cobro real de contrato',
            'DESCUENTO'   => 'Descuento por refinanciamiento',
            'CONDONACION' => 'Monto perdonado (no ingreso real)',
            'SIN_TXN'     => 'Sin tipo de transacción',
        ];
        foreach ($byTxn as $r) {
            $this->line(
                str_pad($r->txn, 16) . str_pad(number_format($r->cnt), 8) .
                str_pad('$' . number_format((float)$r->cap, 0), 16) .
                str_pad('$' . number_format((float)$r->interes, 0), 14) .
                str_pad('$' . number_format((float)$r->impuesto, 0), 13) .
                str_pad('$' . number_format((float)$r->cargos, 0), 13) .
                str_pad('$' . number_format((float)$r->tot, 2), 17) .
                ($txnDesc[$r->txn] ?? '—')
            );
        }

        // ── B. Por columna (componentes) ──────────────────────────────────────
        $this->line('');
        $this->info('════ B. TOTALES POR COLUMNA ════');

        $cols = DB::table('fact_recoveries')->whereIn('period_id', $dataIds)
            ->selectRaw('SUM(capital) as cap, SUM(interest) as interes2, SUM(tax) as tax2, SUM(charges) as ch, SUM(charges_due) as chd, SUM(total_amount) as tot, SUM(savehearts_crece_share) as sh30')
            ->first();

        $this->line(str_pad('Columna', 30) . str_pad('Monto', 20) . 'Descripción');
        $this->line(str_repeat('─', 72));
        foreach ([
            ['capital',              (float)$cols->cap,     'Capital cobrado'],
            ['interest',             (float)$cols->interes2,'Interés cobrado'],
            ['tax',                  (float)$cols->tax2,    'Impuesto cobrado'],
            ['charges',              (float)$cols->ch,   'Cargos normales'],
            ['charges_due',          (float)$cols->chd,  'Cargos vencimiento (acumulado)'],
            ['savehearts_crece_share',(float)$cols->sh30,'Pólizas CRECE 30%'],
            ['total_amount (SUMA DB)',(float)$cols->tot, 'Suma directa de la columna'],
        ] as [$col, $val, $desc]) {
            $this->line(str_pad($col, 30) . str_pad('$' . number_format($val, 2), 20) . $desc);
        }

        // ── C. Por producto (top 20) ──────────────────────────────────────────
        $this->line('');
        $this->info('════ C. TOTALES POR PRODUCTO (top 20) ════');

        $byProd = DB::table('fact_recoveries')->whereIn('period_id', $dataIds)
            ->select('product_name', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(total_amount) as tot'))
            ->groupBy('product_name')->orderByDesc('tot')->limit(20)->get();

        $this->line(str_pad('Producto', 35) . str_pad('Regs', 8) . 'Total');
        $this->line(str_repeat('─', 65));
        foreach ($byProd as $p) {
            $this->line(str_pad(mb_substr($p->product_name ?? 'NULL', 0, 33), 35) .
                        str_pad(number_format($p->cnt), 8) . '$' . number_format((float)$p->tot, 2));
        }

        // ── D. Por origen / concepto ──────────────────────────────────────────
        $this->line('');
        $this->info('════ D. TOTALES POR ORIGEN / OPERACIÓN ════');

        $byOp = DB::table('fact_recoveries')->whereIn('period_id', $dataIds)
            ->select('operation', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(total_amount) as tot'))
            ->groupBy('operation')->orderByDesc('tot')->get();

        $this->line(str_pad('Operación', 35) . str_pad('Regs', 8) . 'Total');
        $this->line(str_repeat('─', 65));
        foreach ($byOp as $op) {
            $this->line(str_pad(mb_substr($op->operation ?? 'NULL', 0, 33), 35) .
                        str_pad(number_format($op->cnt), 8) . '$' . number_format((float)$op->tot, 2));
        }

        // ── E. Registros excluidos (candidatos) ──────────────────────────────
        $this->line('');
        $this->info('════ E. CANDIDATOS A EXCLUIR / YA EXCLUIDOS ════');

        $savehearts = DB::table('fact_recoveries')->whereIn('period_id', $dataIds)
            ->where('is_savehearts', true)->selectRaw('COUNT(*) as cnt, SUM(total_amount) as tot')->first();
        $saveheartsNoCRECE = DB::table('fact_recoveries')->whereIn('period_id', $dataIds)
            ->where('is_savehearts', true)->where('savehearts_crece_share', '=', 0)
            ->selectRaw('COUNT(*) as cnt, SUM(total_amount) as tot')->first();
        $condonacion = DB::table('fact_recoveries')->whereIn('period_id', $dataIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'CONDONACION'")
            ->selectRaw('COUNT(*) as cnt, SUM(total_amount) as tot')->first();
        $descuento = DB::table('fact_recoveries')->whereIn('period_id', $dataIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'DESCUENTO'")
            ->selectRaw('COUNT(*) as cnt, SUM(total_amount) as tot')->first();

        $this->line(str_pad('Concepto', 40) . str_pad('Regs', 8) . str_pad('Monto', 18) . 'Tratamiento actual');
        $this->line(str_repeat('─', 80));
        foreach ([
            ['Savehearts/Seguros (is_savehearts=true)',  $savehearts,       'Incluidos — 30% reconocido como CRECE'],
            ['Savehearts NO CRECE (share=0)',            $saveheartsNoCRECE,'Incluidos — sin partición por producto'],
            ['CONDONACION (monto perdonado)',             $condonacion,      'INCLUIDOS en sistema — puede ser exceso'],
            ['DESCUENTO (refinanciamiento)',              $descuento,        'INCLUIDOS en sistema — puede ser exceso'],
        ] as [$lbl, $row, $nota]) {
            $cnt = (int)($row?->cnt ?? 0);
            $tot = (float)($row?->tot ?? 0);
            $this->line(str_pad(mb_substr($lbl, 0, 38), 40) . str_pad(number_format($cnt), 8) .
                        str_pad('$' . number_format($tot, 0), 18) . $nota);
        }

        // ── F. Tabla de combinaciones vs referencia ───────────────────────────
        $this->line('');
        $this->info('════ F. COMBINACIONES vs REFERENCIA $' . number_format($ref, 2) . ' ════');

        $ids = implode(',', $dataIds);

        $vPago     = (float) DB::table('fact_recoveries')->whereIn('period_id', $dataIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'PAGO'")->sum('total_amount');
        $vDesc     = (float) DB::table('fact_recoveries')->whereIn('period_id', $dataIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'DESCUENTO'")->sum('total_amount');
        $vCond     = (float) DB::table('fact_recoveries')->whereIn('period_id', $dataIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'CONDONACION'")->sum('total_amount');
        $vTotal    = $vPago + $vDesc + $vCond;

        // PAGO capital only (sin interés, impuesto, cargos)
        $vPagoCap  = (float) DB::table('fact_recoveries')->whereIn('period_id', $dataIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'PAGO'")->sum('capital');

        // PAGO + DESCUENTO capital only
        $vPagoDescCap = (float) DB::table('fact_recoveries')->whereIn('period_id', $dataIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) IN ('PAGO','DESCUENTO')")->sum('capital');

        // PAGO + CONDONACION
        $vPagoCond = $vPago + $vCond;
        // PAGO + DESCUENTO sin savehearts-no-CRECE
        $vPagoDescNoSh = (float) DB::table('fact_recoveries')->whereIn('period_id', $dataIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) IN ('PAGO','DESCUENTO')")
            ->where(function($q){ $q->where('is_savehearts', false)->orWhere('savehearts_crece_share', '>', 0); })
            ->sum('total_amount');

        $combinations = [
            ['Solo PAGO (cobros reales)',                         $vPago],
            ['Solo PAGO — solo capital',                          $vPagoCap],
            ['PAGO + CONDONACION',                                $vPagoCond],
            ['PAGO + DESCUENTO',                                  $vPago + $vDesc],
            ['PAGO + DESCUENTO sin savehearts-no-CRECE',          $vPagoDescNoSh],
            ['PAGO + DESCUENTO — solo capital',                   $vPagoDescCap],
            ['PAGO + DESCUENTO + CONDONACION (actual sistema)',   $vTotal],
            ['REFERENCIA',                                        $ref],
        ];

        $this->line(str_pad('Combinación', 52) . str_pad('Monto', 20) . str_pad('Diff vs Ref', 16) . 'Match');
        $this->line(str_repeat('─', 100));
        foreach ($combinations as [$lbl, $val]) {
            $diff  = $val - $ref;
            $sign  = $diff >= 0 ? '+' : '';
            $match = abs($diff) < 50 ? '✓ EXACTO' : (abs($diff) < 5000 ? '≈ CERCANO' : '');
            $this->line(
                str_pad($lbl, 52) .
                str_pad('$' . number_format($val, 2), 20) .
                str_pad($sign . '$' . number_format($diff, 2), 16) .
                $match
            );
        }

        $this->line('');
        $this->info('════ CONCLUSIÓN ════');
        $closest = collect($combinations)->filter(fn($c) => $c[0] !== 'REFERENCIA')
            ->sortBy(fn($c) => abs($c[1] - $ref))->first();
        $this->info("  Combinación más cercana: '{$closest[0]}'");
        $this->info('  Monto: $' . number_format($closest[1], 2) . ' — Diferencia: $' . number_format(abs($closest[1] - $ref), 2));
        $this->line('');
        $this->warn('  Para confirmar la regla exacta de ingresos:');
        $this->line('  1. Revisar si DESCUENTO (refinanciamiento) se considera ingreso en tu referencia');
        $this->line('  2. Revisar si CONDONACION se incluye o excluye');
        $this->line('  3. Revisar si hay exclusión de sucursales (Norte, Corporativo) en tu referencia');
        $this->line('  4. Revisar si charges_due (cargos vencimiento) se suman o no');
    }

    private function showDetailConciliation(array $dataIds, string $label): void
    {
        $this->line('');
        $this->info('════════════════════════════════════════════════════════════════');
        $this->info('  CONCILIACIÓN DETALLADA — Tipos de transacción en fact_recoveries');
        $this->info('════════════════════════════════════════════════════════════════');

        // ── A. Por tipo de transacción (campo en raw_payload) ────────────────
        $this->line('');
        $this->info('════ A. POR TIPO DE TRANSACCIÓN (raw_payload.transaction) ════');
        $this->line('  Interpretación: PAGO = cobro real / DESCUENTO = refinanciamiento / CONDONACION = monto perdonado');
        $this->line('');

        $byTxn = DB::select("
            SELECT
                JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) as txn,
                COUNT(*) as cnt,
                SUM(capital) as cap,
                SUM(interest) as interes,
                SUM(tax) as tax2,
                SUM(charges) as ch,
                SUM(charges_due) as chd,
                SUM(total_amount) as tot
            FROM fact_recoveries
            WHERE period_id IN (" . implode(',', $dataIds) . ")
            GROUP BY txn
            ORDER BY tot DESC
        ");

        $this->line(str_pad('Transacción', 16) . str_pad('Regs', 8) . str_pad('Capital', 16) .
                    str_pad('Int+Tax+Ch', 16) . str_pad('Charges Due', 14) . str_pad('Total', 17) . 'Nota');
        $this->line(str_repeat('─', 100));

        $txnNotes = [
            'PAGO'        => 'Cobro real de contrato',
            'DESCUENTO'   => 'Refinanciamiento (deuda rolada)',
            'CONDONACION' => 'Monto perdonado/condonado (NO ingreso)',
        ];

        foreach ($byTxn as $r) {
            $txn      = $r->txn ?? 'NULL';
            $intTaxCh = (float)$r->interes + (float)$r->tax2 + (float)$r->ch;
            $note     = $txnNotes[$txn] ?? '—';
            $this->line(
                str_pad($txn, 16) .
                str_pad(number_format($r->cnt), 8) .
                str_pad('$' . number_format((float)$r->cap, 0), 16) .
                str_pad('$' . number_format($intTaxCh, 0), 16) .
                str_pad('$' . number_format((float)$r->chd, 0), 14) .
                str_pad('$' . number_format((float)$r->tot, 2), 17) .
                $note
            );
        }

        // ── B. Comparativa de enfoques ────────────────────────────────────────
        $this->line('');
        $this->info('════ B. COMPARATIVA DE ENFOQUES DE CÁLCULO ════');

        $totalAll = DB::table('fact_recoveries')->whereIn('period_id', $dataIds)->sum('total_amount');

        $totalPago = DB::table('fact_recoveries')->whereIn('period_id', $dataIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'PAGO'")
            ->sum('total_amount');

        $totalPagoDesc = DB::table('fact_recoveries')->whereIn('period_id', $dataIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) IN ('PAGO','DESCUENTO')")
            ->sum('total_amount');

        $totalSinCond = DB::table('fact_recoveries')->whereIn('period_id', $dataIds)
            ->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')),'PAGO') != 'CONDONACION'")
            ->sum('total_amount');

        $refIngresos = 18_792_939.79;

        // Nueva fórmula validada: suma todo, excluye solo seguros, CRECE al 30%
        $totalNueva = (float) DB::table('fact_recoveries')->whereIn('period_id', $dataIds)
            ->selectRaw("SUM(CASE
                WHEN is_savehearts = 1 THEN COALESCE(savehearts_crece_share, 0)
                WHEN is_savehearts = 0 AND (UPPER(COALESCE(concept,'')) LIKE '%COBERTURA%' OR UPPER(COALESCE(operation,'')) LIKE '%COBERTURA%') THEN 0
                ELSE total_amount
            END) as t")->value('t') ?? 0;

        $rows = [
            ['Total bruto (sin filtros)',                    (float)$totalAll,     'SUM(total_amount) completo'],
            ['Solo PAGO',                                    (float)$totalPago,    'filtro transacción PAGO'],
            ['PAGO + DESCUENTO',                             (float)$totalPagoDesc,'filtro PAGO + DESCUENTO'],
            ['Nueva fórmula seguros (activa)',               $totalNueva,          '← Regla actual del sistema'],
            ['REFERENCIA validada Excel',                   $refIngresos,         '← Target $18,792,939.79'],
        ];

        $this->line(str_pad('Enfoque', 46) . str_pad('Monto', 20) . str_pad('Diff vs Ref', 16) . 'Nota');
        $this->line(str_repeat('─', 100));
        foreach ($rows as [$label2, $val, $note]) {
            $diff  = $val - $refIngresos;
            $sign  = $diff >= 0 ? '+' : '';
            $this->line(
                str_pad($label2, 46) .
                str_pad('$' . number_format($val, 2), 20) .
                str_pad($sign . '$' . number_format($diff, 2), 16) .
                $note
            );
        }

        // ── C. REGLA PROVISIONAL: PAGO + DESCUENTO válido (12 suc operativas) ─
        $this->line('');
        $this->info('════ C. REGLA PROVISIONAL — PAGO + DESCUENTO (13 sucursales operativas) ════');
        $this->line('  DESCUENTO = refinanciamiento: el cliente paga su contrato anterior al recibir uno nuevo.');
        $this->line('  No es efectivo nuevo, pero sí recuperación contable del crédito vigente.');
        $this->line('  CONDONACION = monto perdonado → EXCLUIDA (no es recuperación real).');
        $this->line('');

        $resolver    = app(\App\Services\BranchResolverService::class);
        $allBranches = DB::table('branches')->get();
        $operativeIds = [];
        foreach ($allBranches as $b) {
            $real = $resolver->resolveRealBranchFromRoute($b->name);
            if ($real && $resolver->isSheetBranch($real)) {
                $operativeIds[] = (int) $b->id;
            }
        }

        $pagoBr  = (float) DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)->whereIn('branch_id', $operativeIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'PAGO'")
            ->sum('total_amount');
        $descBr  = (float) DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)->whereIn('branch_id', $operativeIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'DESCUENTO'")
            ->sum('total_amount');
        $condBr  = (float) DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)->whereIn('branch_id', $operativeIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'CONDONACION'")
            ->sum('total_amount');
        $proposed = $pagoBr + $descBr;

        $this->line(str_pad('Componente', 46) . str_pad('Monto', 18) . 'Decisión');
        $this->line(str_repeat('─', 80));
        $this->line(str_pad('PAGO (13 sucursales operativas)', 46) .
                    str_pad('$' . number_format($pagoBr, 2), 18) . '✓ INCLUIR');
        $this->info(str_pad('+ DESCUENTO válido (refinanciamiento, 12 suc)', 46) .
                    str_pad('+$' . number_format($descBr, 2), 18) . '✓ INCLUIR (decisión provisional)');
        $this->warn(str_pad('− CONDONACION excluida (monto perdonado)', 46) .
                    str_pad('−$' . number_format($condBr, 2), 18) . '✗ EXCLUIDA');
        $this->line(str_repeat('─', 80));
        $this->info(str_pad('= INGRESOS PROPUESTOS (PAGO + DESCUENTO)', 46) . '$' . number_format($proposed, 2));

        $this->line('');
        $diffRef = $proposed - $refIngresos;
        $sign    = $diffRef >= 0 ? '+' : '';
        $this->line(str_pad('Referencia usuario ($18,332,149.55)', 46) . '$' . number_format($refIngresos, 2));
        $diffLine = str_pad('Diferencia vs referencia', 46) . $sign . '$' . number_format($diffRef, 2);
        if ($diffRef > 0) {
            $this->warn($diffLine . '  ← PAGO+DESC 12suc supera la referencia');
        } else {
            $this->line($diffLine);
        }

        // Desglose DESCUENTO por ciudad
        $this->line('');
        $this->line('  Desglose DESCUENTO por ciudad (12 suc operativas):');
        $descByBranch = DB::table('fact_recoveries as fr')
            ->leftJoin('branches as b', 'fr.branch_id', '=', 'b.id')
            ->whereIn('fr.period_id', $dataIds)
            ->whereIn('fr.branch_id', $operativeIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(fr.raw_payload, '$.transaction')) = 'DESCUENTO'")
            ->select('b.name as branch_name', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(fr.total_amount) as total'))
            ->groupBy('fr.branch_id', 'b.name')
            ->orderByDesc('total')
            ->get();

        $cityDesc = [];
        foreach ($descByBranch as $br) {
            $city = $resolver->resolveRealBranchFromRoute($br->branch_name ?? '') ?? $br->branch_name ?? '?';
            $cityDesc[$city] = ($cityDesc[$city] ?? 0) + (float) $br->total;
        }
        arsort($cityDesc);
        foreach ($cityDesc as $city => $amt) {
            $this->line('    ' . str_pad(mb_substr($city, 0, 24), 26) . '$' . number_format($amt, 2));
        }
        $this->line('    ' . str_repeat('─', 38));
        $this->line('    ' . str_pad('TOTAL DESCUENTO (12 suc)', 26) . '$' . number_format($descBr, 2));

        // ── D. DIAGNÓSTICO ────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ D. DIAGNÓSTICO ════');
        $this->line('  Brecha actual vs referencia: $' . number_format((float)$totalAll - $refIngresos, 2));
        $this->line('  DESCUENTO ($' . number_format($descBr, 2) . ', 12 suc): Refinanciamiento. Decisión provisional: INCLUIR.');
        $this->line('  CONDONACION (12 suc $' . number_format($condBr, 2) . '): Monto perdonado. EXCLUIDO.');
        $this->line('  Cargos vencimiento (charges_due): $' . number_format(
            (float)DB::table('fact_recoveries')->whereIn('period_id', $dataIds)->sum('charges_due'), 2
        ) . ' — acumulado, puede no ser cobro real.');
        $this->line('');
        $this->info('  → Ingresos provisionales (PAGO + DESCUENTO, 12 suc): $' . number_format($proposed, 2));
        $this->line('    Si PAGO solo: $' . number_format($pagoBr, 2) . ' (falta $' . number_format($refIngresos - $pagoBr, 2) . ' para ref)');
        $this->line('    Si PAGO+DESCUENTO: $' . number_format($proposed, 2) . ' (diferencia vs ref: ' . $sign . '$' . number_format($diffRef, 2) . ')');
    }

    private function showDireccionComercial(array $dataIds, string $label): void
    {
        // Reference total from Dirección Comercial report (April 2026).
        // Known conciliation diff of ~$710: ATLIXCO/CRECE ~$500 + TULA/i30 ~$210. Not a bug — documented.
        $dcRef = 18_323_749.55;

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════════════════');
        $this->info("  CONCILIACIÓN vs DIRECCIÓN COMERCIAL — {$label}");
        $this->info('  Referencia DC: $' . number_format($dcRef, 2));
        $this->info('  Regla: PAGO + DESCUENTO | Excluir: CONDONACION, COBERTURA SAVEHEARTS, COMISIÓN POR APERTURA');
        $this->info('════════════════════════════════════════════════════════════════════════');

        $resolver     = app(BranchResolverService::class);
        $allBranches  = DB::table('branches')->get();
        $operativeIds = [];
        foreach ($allBranches as $b) {
            $real = $resolver->resolveRealBranchFromRoute($b->name);
            if ($real && $resolver->isSheetBranch($real)) $operativeIds[] = (int) $b->id;
        }

        // ── Per-branch breakdown (PAGO + DESCUENTO depurado) ─────────────────
        $rows = DB::table('fact_recoveries as fr')
            ->leftJoin('branches as b', 'fr.branch_id', '=', 'b.id')
            ->whereIn('fr.period_id', $dataIds)
            ->whereIn('fr.branch_id', $operativeIds)
            ->whereIn('fr.transaction', ['PAGO', 'DESCUENTO'])
            ->whereRaw("UPPER(COALESCE(fr.concept, '')) NOT LIKE '%COBERTURA SAVEHEARTS%'")
            ->whereRaw("UPPER(COALESCE(fr.operation, '')) NOT LIKE '%COMISION POR APERTURA%'")
            ->select(
                'b.name as branch_name',
                'fr.transaction',
                DB::raw('SUM(fr.capital) as capital'),
                DB::raw('SUM(fr.interest) as interes'),
                DB::raw('SUM(fr.tax) as impuesto'),
                DB::raw('SUM(fr.charges) as cargos'),
                DB::raw('SUM(fr.charges_due) as moratorios'),
                DB::raw('SUM(fr.total_amount) as total')
            )
            ->groupBy('fr.branch_id', 'b.name', 'fr.transaction')
            ->get();

        $byCity = [];
        foreach ($rows as $r) {
            $city = $resolver->resolveRealBranchFromRoute($r->branch_name ?? '') ?? $r->branch_name ?? '?';
            if (!isset($byCity[$city])) {
                $byCity[$city] = ['capital' => 0.0, 'interes' => 0.0, 'impuesto' => 0.0, 'cargos' => 0.0, 'moratorios' => 0.0, 'pago' => 0.0, 'descuento' => 0.0, 'total' => 0.0];
            }
            $byCity[$city]['capital']   += (float)$r->capital;
            $byCity[$city]['interes']   += (float)$r->interes;
            $byCity[$city]['impuesto']  += (float)$r->impuesto;
            $byCity[$city]['cargos']    += (float)$r->cargos;
            $byCity[$city]['moratorios']+= (float)$r->moratorios;
            $byCity[$city]['total']     += (float)$r->total;
            if ($r->transaction === 'PAGO')      $byCity[$city]['pago']     += (float)$r->total;
            if ($r->transaction === 'DESCUENTO') $byCity[$city]['descuento'] += (float)$r->total;
        }
        uasort($byCity, fn($a, $b) => $b['total'] <=> $a['total']);

        $this->line('');
        $this->line(str_pad('Sucursal', 22) . str_pad('Capital', 14) . str_pad('Interés', 13) .
                    str_pad('Impuesto', 12) . str_pad('Cargos Cal', 13) . str_pad('Moratorios', 13) .
                    str_pad('PAGO', 14) . str_pad('DESCUENTO', 14) . 'Total sistema');
        $this->line(str_repeat('─', 130));

        $grandTotal = 0.0;
        $grandPago  = 0.0;
        $grandDesc  = 0.0;
        foreach ($byCity as $city => $d) {
            $this->line(
                str_pad(mb_substr($city, 0, 20), 22) .
                str_pad('$' . number_format($d['capital'], 0), 14) .
                str_pad('$' . number_format($d['interes'], 0), 13) .
                str_pad('$' . number_format($d['impuesto'], 0), 12) .
                str_pad('$' . number_format($d['cargos'], 0), 13) .
                str_pad('$' . number_format($d['moratorios'], 0), 13) .
                str_pad('$' . number_format($d['pago'], 0), 14) .
                str_pad('$' . number_format($d['descuento'], 0), 14) .
                '$' . number_format($d['total'], 2)
            );
            $grandTotal += $d['total'];
            $grandPago  += $d['pago'];
            $grandDesc  += $d['descuento'];
        }
        $this->line(str_repeat('─', 130));
        $diff = $grandTotal - $dcRef;
        $sign = $diff >= 0 ? '+' : '';
        $this->info(str_pad('TOTAL SISTEMA (12 suc)', 22) . str_pad('', 106) . '$' . number_format($grandTotal, 2));
        $this->line(str_pad('Referencia DC', 22) . str_pad('', 106) . '$' . number_format($dcRef, 2));
        $diffLine = str_pad('Diferencia', 22) . str_pad('', 106) . $sign . '$' . number_format($diff, 2);
        if (abs($diff) < 2_000) $this->warn($diffLine . '  ← dentro del rango de conciliación aceptable');
        else                    $this->error($diffLine . '  ← diferencia significativa — revisar');

        // ── Subtotales por tipo de transacción ───────────────────────────────
        $this->line('');
        $this->info('── Subtotales por tipo ──');
        $this->line('  PAGO      (cobros reales):          $' . number_format($grandPago, 2));
        $this->info('  DESCUENTO (refinanciamiento):       $' . number_format($grandDesc, 2) . '  ← incluido por decisión de negocio');

        // ── Montos excluidos ─────────────────────────────────────────────────
        $this->line('');
        $this->info('── Montos excluidos de este total ──');

        $excCondonacion = (float) DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $operativeIds)
            ->where('transaction', 'CONDONACION')
            ->sum('total_amount');

        $excSavehearts = (float) DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $operativeIds)
            ->whereRaw("UPPER(COALESCE(concept, '')) LIKE '%COBERTURA SAVEHEARTS%'")
            ->whereIn('transaction', ['PAGO', 'DESCUENTO'])
            ->sum('total_amount');

        $excComision = (float) DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $operativeIds)
            ->whereRaw("UPPER(COALESCE(operation, '')) LIKE '%COMISION POR APERTURA%'")
            ->whereIn('transaction', ['PAGO', 'DESCUENTO'])
            ->sum('total_amount');

        $this->warn('  CONDONACION (monto perdonado):     $' . number_format($excCondonacion, 2));
        $this->warn('  COBERTURA SAVEHEARTS (concepto):   $' . number_format($excSavehearts, 2));
        $this->warn('  COMISIÓN POR APERTURA (operación): $' . number_format($excComision, 2));

        // ── Nota de conciliación ──────────────────────────────────────────────
        $this->line('');
        $this->info('── Nota de conciliación (~$710 diferencia conocida) ──');
        $this->line('  ATLIXCO / CRECE:   ~$500  — registros de plataforma vs corte DC');
        $this->line('  TULA   / i30:      ~$210  — idem');
        $this->line('  Total diferencia conocida: ~$710 — NO se aplica regla manual para eliminarla.');
        $this->line('  Esta diferencia se documenta como conciliación aceptable con DC.');
    }

    private function showDescuentoBreakdown(array $dataIds): void
    {
        $ref = 18_792_939.79;

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════════════════');
        $this->info('  DESGLOSE DESCUENTO REFINANCIAMIENTO — componente del total cobranza');
        $this->info('  Referencia: $' . number_format($ref, 2) . ' (validada Excel)');
        $this->info('════════════════════════════════════════════════════════════════════════');

        $resolver    = app(\App\Services\BranchResolverService::class);
        $allBranches = DB::table('branches')->get();
        $operativeMap = [];
        foreach ($allBranches as $b) {
            $real = $resolver->resolveRealBranchFromRoute($b->name);
            if ($real && $resolver->isSheetBranch($real)) {
                $operativeMap[(int) $b->id] = $real;
            }
        }
        $operativeIds = array_keys($operativeMap);

        $vPago = (float) DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $operativeIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'PAGO'")
            ->sum('total_amount');

        $this->line('');
        $this->line('  PAGO (12 suc):                   $' . number_format($vPago, 2));
        $this->line('  Falta para llegar a referencia:  $' . number_format($ref - $vPago, 2));

        // ── 1. Desglose DESCUENTO por Sucursal / Producto / Operación ─────────
        $this->line('');
        $this->info('════ 1. DESCUENTO refinanciamiento — Sucursal | Producto | Operación ════');

        $descRows = DB::table('fact_recoveries as fr')
            ->leftJoin('branches as b', 'fr.branch_id', '=', 'b.id')
            ->whereIn('fr.period_id', $dataIds)
            ->whereIn('fr.branch_id', $operativeIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(fr.raw_payload, '$.transaction')) = 'DESCUENTO'")
            ->select(
                'b.name as branch_name',
                'fr.product_name',
                'fr.operation',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(fr.capital) as capital'),
                DB::raw('SUM(fr.interest) as interes'),
                DB::raw('SUM(fr.tax) as impuesto'),
                DB::raw('SUM(fr.charges) as cargos'),
                DB::raw('SUM(fr.total_amount) as total')
            )
            ->groupBy('fr.branch_id', 'b.name', 'fr.product_name', 'fr.operation')
            ->orderByDesc('total')
            ->get();

        $this->line(
            str_pad('Sucursal', 22) . str_pad('Producto', 26) . str_pad('Operacion', 20) .
            str_pad('Regs', 7) . str_pad('Capital', 16) . str_pad('Interes', 14) .
            str_pad('Impuesto', 13) . str_pad('Cargos', 13) . 'Total'
        );
        $this->line(str_repeat('-', 150));

        $totD = ['cnt' => 0, 'capital' => 0.0, 'interes' => 0.0, 'impuesto' => 0.0, 'cargos' => 0.0, 'total' => 0.0];
        foreach ($descRows as $r) {
            $city = $resolver->resolveRealBranchFromRoute($r->branch_name ?? '') ?? $r->branch_name ?? '?';
            $this->line(
                str_pad(mb_substr($city, 0, 20), 22) .
                str_pad(mb_substr($r->product_name ?? 'NULL', 0, 24), 26) .
                str_pad(mb_substr($r->operation ?? 'NULL', 0, 18), 20) .
                str_pad(number_format($r->cnt), 7) .
                str_pad('$' . number_format((float)$r->capital, 0), 16) .
                str_pad('$' . number_format((float)$r->interes, 0), 14) .
                str_pad('$' . number_format((float)$r->impuesto, 0), 13) .
                str_pad('$' . number_format((float)$r->cargos, 0), 13) .
                '$' . number_format((float)$r->total, 2)
            );
            $totD['cnt']      += $r->cnt;
            $totD['capital']  += (float)$r->capital;
            $totD['interes']  += (float)$r->interes;
            $totD['impuesto'] += (float)$r->impuesto;
            $totD['cargos']   += (float)$r->cargos;
            $totD['total']    += (float)$r->total;
        }
        $this->line(str_repeat('-', 150));
        $this->info(
            str_pad('TOTAL DESCUENTO (12 suc)', 75) .
            str_pad(number_format($totD['cnt']), 7) .
            str_pad('$' . number_format($totD['capital'], 0), 16) .
            str_pad('$' . number_format($totD['interes'], 0), 14) .
            str_pad('$' . number_format($totD['impuesto'], 0), 13) .
            str_pad('$' . number_format($totD['cargos'], 0), 13) .
            '$' . number_format($totD['total'], 2)
        );

        // ── 2. Escenarios A-F ─────────────────────────────────────────────────
        $this->line('');
        $this->info('════ 2. ESCENARIOS A-F — PAGO + parte del DESCUENTO ════');
        $this->line('  PAGO = $' . number_format($vPago, 2) .
                    '   DESCUENTO completo = $' . number_format($totD['total'], 2) .
                    '   Exceso vs ref = $' . number_format($vPago + $totD['total'] - $ref, 2));
        $this->line('');

        $mkDesc = function (array $where = [], array $whereNot = [], ?string $col = null) use ($dataIds, $operativeIds, $totD): float {
            $q = DB::table('fact_recoveries')
                ->whereIn('period_id', $dataIds)
                ->whereIn('branch_id', $operativeIds)
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'DESCUENTO'");
            foreach ($where    as [$clause, $bind]) $q->whereRaw($clause, $bind);
            foreach ($whereNot as [$clause, $bind]) $q->whereRaw("NOT ($clause)", $bind);
            if ($col === null) return (float)$q->sum('total_amount');
            return (float)$q->selectRaw("SUM({$col}) as t")->value('t');
        };

        $dCapOnly   = $mkDesc([], [], 'capital');
        $dIntTaxCh  = $mkDesc([], [], 'interest + tax + charges');
        $dAll       = $totD['total'];
        $dExclCrece = $mkDesc([["UPPER(COALESCE(product_name,'')) NOT LIKE ?", ['%CRECE%']]]);
        $dExclCD    = $mkDesc([["UPPER(COALESCE(product_name,'')) NOT LIKE ?", ['%CREDITO DIARIO%']]]);
        $dCapGt0    = $mkDesc([['capital > 0', []]]);

        $scenarios = [
            ['A', 'PAGO + DESCUENTO solo capital',           $vPago + $dCapOnly,   'solo columna capital del DESCUENTO'],
            ['B', 'PAGO + DESCUENTO solo int/imp/cargos',    $vPago + $dIntTaxCh,  'interes + impuesto + cargos del DESCUENTO'],
            ['C', 'PAGO + DESCUENTO completo',               $vPago + $dAll,       'DESCUENTO total (todas las columnas)'],
            ['D', 'PAGO + DESCUENTO excl. CRECE',            $vPago + $dExclCrece, 'excluye registros producto CRECE'],
            ['E', 'PAGO + DESCUENTO excl. CREDITO DIARIO',   $vPago + $dExclCD,    'excluye registros CREDITO DIARIO'],
            ['F', 'PAGO + DESCUENTO (capital > 0)',          $vPago + $dCapGt0,    'solo registros donde capital > 0'],
        ];

        $this->line(str_pad('', 4) . str_pad('Escenario', 50) . str_pad('Monto', 20) . str_pad('Diff vs Ref', 18) . str_pad('Match', 12) . 'Nota');
        $this->line(str_repeat('-', 125));
        foreach ($scenarios as [$letter, $lbl, $val, $nota]) {
            $diff  = $val - $ref;
            $sign  = $diff >= 0 ? '+' : '';
            $match = abs($diff) < 100 ? 'EXACTO' : (abs($diff) < 100_000 ? 'CERCANO' : '');
            $line  = str_pad($letter . ')', 4) .
                     str_pad(mb_substr($lbl, 0, 48), 50) .
                     str_pad('$' . number_format($val, 2), 20) .
                     str_pad($sign . '$' . number_format($diff, 2), 18) .
                     str_pad($match, 12) .
                     $nota;
            if ($match === 'EXACTO')   $this->info($line);
            elseif ($match === 'CERCANO') $this->warn($line);
            else                      $this->line($line);
        }
        $this->line(str_repeat('-', 125));
        $this->line(str_pad('    REFERENCIA', 54) . '$' . number_format($ref, 2));

        // ── 3. Escenario G: por sucursal ──────────────────────────────────────
        $this->line('');
        $this->info('════ 3. G — PAGO + DESCUENTO por sucursal ════');

        $byCity = [];
        $allByBranch = DB::table('fact_recoveries as fr')
            ->leftJoin('branches as b', 'fr.branch_id', '=', 'b.id')
            ->whereIn('fr.period_id', $dataIds)
            ->whereIn('fr.branch_id', $operativeIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(fr.raw_payload, '$.transaction')) IN ('PAGO','DESCUENTO')")
            ->select(
                'b.name as branch_name',
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(fr.raw_payload, '$.transaction')) as txn"),
                DB::raw('SUM(fr.total_amount) as total'),
                DB::raw('SUM(fr.capital) as capital')
            )
            ->groupBy('fr.branch_id', 'b.name', DB::raw("JSON_UNQUOTE(JSON_EXTRACT(fr.raw_payload, '$.transaction'))"))
            ->get();

        foreach ($allByBranch as $r) {
            $city = $resolver->resolveRealBranchFromRoute($r->branch_name ?? '') ?? $r->branch_name ?? '?';
            if (!isset($byCity[$city])) $byCity[$city] = ['pago' => 0.0, 'desc' => 0.0, 'desc_cap' => 0.0];
            if ($r->txn === 'PAGO') $byCity[$city]['pago']     += (float)$r->total;
            else                   { $byCity[$city]['desc']     += (float)$r->total;
                                     $byCity[$city]['desc_cap'] += (float)$r->capital; }
        }
        uasort($byCity, fn($a, $b) => ($b['pago'] + $b['desc']) <=> ($a['pago'] + $a['desc']));

        $this->line(str_pad('Ciudad', 22) . str_pad('PAGO', 18) . str_pad('DESC total', 18) . str_pad('DESC capital', 18) . 'PAGO+DESC');
        $this->line(str_repeat('-', 90));
        $gTotal = 0.0;
        foreach ($byCity as $city => $d) {
            $pd = $d['pago'] + $d['desc'];
            $gTotal += $pd;
            $this->line(
                str_pad(mb_substr($city, 0, 20), 22) .
                str_pad('$' . number_format($d['pago'], 0), 18) .
                str_pad('$' . number_format($d['desc'], 0), 18) .
                str_pad('$' . number_format($d['desc_cap'], 0), 18) .
                '$' . number_format($pd, 2)
            );
        }
        $this->line(str_repeat('-', 90));
        $this->line(str_pad('TOTAL (12 suc)', 76) . '$' . number_format($gTotal, 2));
        $diffG = $gTotal - $ref;
        $signG = $diffG >= 0 ? '+' : '';
        $mG = abs($diffG) < 100 ? 'EXACTO' : (abs($diffG) < 100_000 ? 'CERCANO' : '');
        $gLine = '  Diferencia vs referencia: ' . $signG . '$' . number_format($diffG, 2) . '  ' . $mG;
        if ($mG === 'EXACTO')    $this->info($gLine);
        elseif ($mG === 'CERCANO') $this->warn($gLine);
        else                       $this->line($gLine);

        // ── 4. Diagnóstico ────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ 4. DIAGNOSTICO ════');
        $best = collect($scenarios)->sortBy(fn($s) => abs($s[2] - $ref))->first();
        $this->info('  Escenario mas cercano: ' . $best[0] . ') ' . $best[1]);
        $this->info('  Monto: $' . number_format($best[2], 2) . '   Diff: $' . number_format(abs($best[2] - $ref), 2));
        $this->line('');
        $this->line('  Desglose DESCUENTO por producto:');
        $prodRows = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $operativeIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'DESCUENTO'")
            ->select('product_name', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(capital) as capital'), DB::raw('SUM(total_amount) as total'))
            ->groupBy('product_name')
            ->orderByDesc('total')
            ->get();
        foreach ($prodRows as $p) {
            $this->line('    ' . str_pad(mb_substr($p->product_name ?? 'NULL', 0, 35), 38) .
                        str_pad(number_format($p->cnt) . ' regs', 14) .
                        str_pad('cap=$' . number_format((float)$p->capital, 0), 20) .
                        'total=$' . number_format((float)$p->total, 2));
        }
    }
}
