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
                                {--by-branch : show breakdown by branch}
                                {--detail : full conciliation: by transaction type, by data source, approach comparison}
                                {--compare-reference : deep audit against reference $18,332,149.55 with all combinations}';
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

        // ── Resumen por componente ────────────────────────────────────────────
        $this->line('');
        $this->info('════ RESUMEN POR COMPONENTE ════');
        $this->line('');

        $tot = (clone $q)->selectRaw('
            COUNT(*) as filas,
            SUM(capital) as capital,
            SUM(interest) as interes,
            SUM(tax) as impuesto,
            SUM(charges) as cargos,
            SUM(charges_due) as cargos_vcto,
            SUM(total_amount) as total,
            SUM(savehearts_crece_share) as polizas_crece_30,
            SUM(CASE WHEN is_savehearts THEN 1 ELSE 0 END) as savehearts_filas,
            SUM(CASE WHEN savehearts_crece_share > 0 THEN 1 ELSE 0 END) as polizas_con_share
        ')->first();

        $components = [
            ['Capital recuperado',          'fact_recoveries', 'capital',              (float)($tot->capital ?? 0)],
            ['Intereses recuperados',        'fact_recoveries', 'interest',             (float)($tot->interes ?? 0)],
            ['Impuestos recuperados',        'fact_recoveries', 'tax',                  (float)($tot->impuesto ?? 0)],
            ['Cargos calendario',           'fact_recoveries', 'charges',              (float)($tot->cargos ?? 0)],
            ['Cargos vencimiento',          'fact_recoveries', 'charges_due',          (float)($tot->cargos_vcto ?? 0)],
        ];

        $totalSinPolizas = 0.0;
        $this->line(str_pad('Componente', 32) . str_pad('Tabla', 22) . str_pad('Columna', 22) .
                    str_pad('Registros', 12) . 'Monto');
        $this->line(str_repeat('─', 100));

        foreach ($components as [$label, $table, $col, $amt]) {
            $cnt = (clone $q)->whereRaw("{$col} > 0")->count();
            $this->line(
                str_pad($label, 32) . str_pad($table, 22) . str_pad($col, 22) .
                str_pad(number_format($cnt), 12) . '$' . number_format($amt, 2)
            );
            $totalSinPolizas += $amt;
        }

        // Pólizas CRECE 30%
        $polizas30 = (float)($tot->polizas_crece_30 ?? 0);
        $polizasBruto = $polizas30 > 0 ? $polizas30 / 0.30 : 0.0;
        $polizasExcluido = $polizasBruto - $polizas30;

        $this->line(str_repeat('─', 100));
        $this->line(str_pad('Subtotal (sin pólizas)', 32) . str_pad('', 44) . '$' . number_format($totalSinPolizas, 2));

        $this->line('');
        $this->info('── Pólizas CRECE 30% ──');
        if ($polizas30 > 0) {
            $this->info("  ✓ Pólizas CRECE detectadas:");
            $this->line("    Filas con is_savehearts=true:  " . number_format($tot->savehearts_filas ?? 0));
            $this->line("    Filas con share > 0:           " . number_format($tot->polizas_con_share ?? 0));
            $this->line("    Monto bruto estimado:          $" . number_format($polizasBruto, 2));
            $this->line("    30% reconocido (CRECE):        $" . number_format($polizas30, 2) . "  ← INCLUIDO en ingresos");
            $this->line("    70% excluido (cliente):        $" . number_format($polizasExcluido, 2) . "  ← NO incluido");
            $this->line("    Fuente/tabla: fact_recoveries | Columna: savehearts_crece_share");
        } else {
            $this->warn("  ✗ Pólizas CRECE = $0");
            $this->line("    Filas con is_savehearts=true:  " . number_format($tot->savehearts_filas ?? 0));
            $anyShRows  = (clone $q)->where('is_savehearts', true)->count();
            $operSeguro = (clone $q)->whereRaw("LOWER(COALESCE(operation,'')) REGEXP 'seguro|poliza'")->count();
            $this->line("    Filas operacion=seguro/poliza: {$operSeguro}");
            if ($anyShRows === 0 && $operSeguro === 0) {
                $this->line("    → El Excel de cobranza NO contiene filas de tipo SEGURO/PÓLIZA.");
                $this->line("    → O la columna 'Operación' no fue detectada en el import.");
                $this->line("    DIAGNÓSTICO: Revisar con audit-row-trace --source=lendus_ingresos_cobranza.");
            }
        }

        $totalIngresos = $totalSinPolizas + $polizas30;
        $this->line('');
        $this->line(str_repeat('═', 60));
        $this->line(str_pad('TOTAL INGRESOS', 40) . '$' . number_format($totalIngresos, 2));
        $this->line("  (Capital + Interés + Impuesto + Cargos + Pólizas CRECE 30%)");
        $this->line("  Filas totales en fact_recoveries: " . number_format($tot->filas ?? 0));

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

        // ── Por sucursal (filtrado a 12 sucursales operativas) ───────────────
        if ($this->option('by-branch')) {
            $this->line('');
            $this->info('════ DESGLOSE POR SUCURSAL (12 sucursales operativas, PAGO solamente) ════');

            // Build operative branch IDs (same as BranchRadiographyCalculator)
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

            // PAGO only, 12 branches
            $byBranchPago = DB::table('fact_recoveries as fr')
                ->leftJoin('branches as b', 'fr.branch_id', '=', 'b.id')
                ->whereIn('fr.period_id', $dataIds)
                ->whereIn('fr.branch_id', $operativeIds)
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(fr.raw_payload, '$.transaction')) = 'PAGO'")
                ->when($filterProd, fn($q) => $q->whereRaw("UPPER(COALESCE(fr.product_name,'')) LIKE ?", ["%{$filterProd}%"]))
                ->select(
                    'b.name as branch_name',
                    DB::raw('COUNT(*) as cnt'),
                    DB::raw('SUM(fr.capital) as capital'),
                    DB::raw('SUM(fr.interest) as interes'),
                    DB::raw('SUM(fr.tax) as impuesto'),
                    DB::raw('SUM(fr.charges) as cargos'),
                    DB::raw('SUM(fr.total_amount) as total')
                )
                ->groupBy('fr.branch_id', 'b.name')
                ->orderByDesc('total')
                ->get();

            // Group by resolved city name
            $byCity = [];
            foreach ($byBranchPago as $br) {
                $city = $resolver->resolveRealBranchFromRoute($br->branch_name ?? '') ?? $br->branch_name ?? 'Desconocida';
                if (!isset($byCity[$city])) {
                    $byCity[$city] = ['cnt' => 0, 'capital' => 0, 'interes' => 0, 'impuesto' => 0, 'cargos' => 0, 'total' => 0];
                }
                $byCity[$city]['cnt']      += $br->cnt;
                $byCity[$city]['capital']  += (float) $br->capital;
                $byCity[$city]['interes']  += (float) $br->interes;
                $byCity[$city]['impuesto'] += (float) $br->impuesto;
                $byCity[$city]['cargos']   += (float) $br->cargos;
                $byCity[$city]['total']    += (float) $br->total;
            }

            arsort($byCity);

            $this->line(str_pad('Ciudad (resuelta)', 24) . str_pad('Regs', 7) . str_pad('Capital', 16) .
                        str_pad('Interés', 14) . str_pad('Impuesto', 13) . str_pad('Cargos', 13) . 'Total');
            $this->line(str_repeat('─', 100));

            $pagoTotal = 0.0;
            foreach ($byCity as $city => $data) {
                $this->line(
                    str_pad(mb_substr($city, 0, 22), 24) .
                    str_pad(number_format($data['cnt']), 7) .
                    str_pad('$' . number_format($data['capital'], 0), 16) .
                    str_pad('$' . number_format($data['interes'], 0), 14) .
                    str_pad('$' . number_format($data['impuesto'], 0), 13) .
                    str_pad('$' . number_format($data['cargos'], 0), 13) .
                    '$' . number_format($data['total'], 0)
                );
                $pagoTotal += $data['total'];
            }
            $this->line(str_repeat('─', 100));
            $this->line(str_pad('TOTAL PAGO (12 sucursales)', 24) . str_pad('', 7) .
                        str_pad('', 56) . '$' . number_format($pagoTotal, 2));

            // Show orphan branches (excluded)
            $orphanBranches = DB::table('fact_recoveries as fr')
                ->leftJoin('branches as b', 'fr.branch_id', '=', 'b.id')
                ->whereIn('fr.period_id', $dataIds)
                ->whereNotIn('fr.branch_id', $operativeIds)
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(fr.raw_payload, '$.transaction')) = 'PAGO'")
                ->select(
                    DB::raw('COALESCE(b.name, "Sin sucursal") as sucursal'),
                    DB::raw('COUNT(*) as cnt'),
                    DB::raw('SUM(fr.total_amount) as total')
                )
                ->groupBy('fr.branch_id', 'b.name')
                ->orderByDesc('total')
                ->get();

            if ($orphanBranches->isNotEmpty()) {
                $this->line('');
                $this->warn('  ⚠ SUCURSALES HUÉRFANAS EXCLUIDAS (no resuelven a 12 ciudades, accredited_name=NULL):');
                $orphanTotal = 0.0;
                foreach ($orphanBranches as $ob) {
                    $this->warn('    ' . str_pad(mb_substr($ob->sucursal, 0, 22), 24) .
                                str_pad(number_format($ob->cnt), 7) . '$' . number_format((float)$ob->total, 2));
                    $orphanTotal += (float) $ob->total;
                }
                $this->warn('    TOTAL HUÉRFANAS: $' . number_format($orphanTotal, 2));
                $this->warn('    → NO incluidos en el cálculo EBITDA actual.');
                $this->warn('    → Para incluirlas: agregar a routeMap en BranchResolverService.');
            }
        }

        if ($this->option('detail')) {
            $this->showDetailConciliation($dataIds, $period->label);
        }

        if ($this->option('compare-reference')) {
            $this->showCompareReference($dataIds, $period->label);
        }

        return 0;
    }

    private function showCompareReference(array $dataIds, string $label): void
    {
        $ref = 18_332_149.55;

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA PROFUNDA INGRESOS — {$label}");
        $this->info('  Referencia: $' . number_format($ref, 2));
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

        $refIngresos  = 18_332_149.55;

        $rows = [
            ['ACTUAL (sistema, todas las transacciones)',   (float)$totalAll,     '← Actualmente usado en EBITDA'],
            ['Solo PAGO (cobros reales)',                    (float)$totalPago,    '← DebugRadiografía usa este'],
            ['PAGO + DESCUENTO (sin CONDONACIÓN)',           (float)$totalPagoDesc,''],
            ['Sin CONDONACIÓN (PAGO + DESCUENTO)',           (float)$totalSinCond, ''],
            ['REFERENCIA abril 2026',                       $refIngresos,         '← Target del usuario'],
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
        $this->info('════ C. REGLA PROVISIONAL — PAGO + DESCUENTO (12 sucursales operativas) ════');
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
        $this->line(str_pad('PAGO (12 sucursales operativas)', 46) .
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
}
