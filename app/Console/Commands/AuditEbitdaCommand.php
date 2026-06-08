<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Audits EBITDA calculation.
 * EBITDA = Ingresos Totales - Otorgamientos - Gastos Totales
 * Gastos Totales = Gastos Operativos + Nómina NOI (percepciones − descuentos empleado)
 * Envío a corporativo/sobrante se muestra DESPUÉS del EBITDA como distribución.
 */
class AuditEbitdaCommand extends Command
{
    protected $signature   = 'reportes:audit-ebitda
                                {period_id}
                                {--detail : show per-line registros, archivo, tabla, columna, razón}
                                {--compare-reference : compare current totals vs user reference values}';
    protected $description = 'Auditoría EBITDA: Ingresos − Otorgamientos − Gastos Totales (no resta nómina doble, excluye descuentos NOI)';

    private const EXCLUDED_FROM_GASTOS = [
        'Nómina y Capital Humano',
        'Envío de utilidad a corporativo',
        'Préstamos Intersucursales',
    ];

    // NOI D-codes that are employee deductions, NOT additional company cost
    private const NOI_DEDUCTION_CONCEPTS = ['D094', 'D010', 'D113', 'D123', 'D004', 'D111'];

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

        $detail = (bool) $this->option('detail');

        $this->info("════════════════════════════════════════════════════════════════");
        $this->info("  AUDITORÍA EBITDA — {$period->label} (ID {$period->id})");
        $this->info("════════════════════════════════════════════════════════════════");
        $this->line('');
        $this->info('  Fórmula: EBITDA = Ingresos Totales − Otorgamientos − Gastos Totales');
        $this->info('  Gastos Totales = Gastos Operativos + Nómina (percepciones − descuentos NOI)');
        $this->info('  Envío a corporativo: NO entra al EBITDA — se muestra como distribución posterior');
        $this->line('');

        // ── A. INGRESOS TOTALES ───────────────────────────────────────────────
        $this->info('════ A. INGRESOS TOTALES (fact_recoveries) ════');

        $rec = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->selectRaw('
                COUNT(*) as filas,
                SUM(capital) as capital,
                SUM(interest) as interes,
                SUM(tax) as impuesto,
                SUM(charges) as cargos,
                SUM(total_amount) as total,
                SUM(savehearts_crece_share) as polizas_crece_30
            ')
            ->first();

        $totalIngresos = (float)$rec->total;
        $polizas30     = (float)$rec->polizas_crece_30;

        $this->line(str_pad('Concepto', 40) . str_pad('Fuente/Tabla', 22) . str_pad('Columna', 22) . 'Monto');
        $this->line(str_repeat('-', 100));
        foreach ([
            ['Capital cobrado',    'fact_recoveries', 'capital',       (float)$rec->capital],
            ['Interés cobrado',    'fact_recoveries', 'interest',      (float)$rec->interes],
            ['Impuesto cobrado',   'fact_recoveries', 'tax',           (float)$rec->impuesto],
            ['Cargos/muletas',     'fact_recoveries', 'charges',       (float)$rec->cargos],
            ['Pólizas CRECE 30%',  'fact_recoveries', 'sh_crece_share',$polizas30],
        ] as [$label, $table, $col, $amt]) {
            $this->line(str_pad($label, 40) . str_pad($table, 22) . str_pad($col, 22) . '$' . number_format($amt, 2));
        }
        $this->line(str_repeat('-', 100));
        $this->line(str_pad('TOTAL INGRESOS', 86) . '$' . number_format($totalIngresos, 2));
        $this->line("  Filas en fact_recoveries: " . number_format($rec->filas));
        $this->line('');

        // ── B. OTORGAMIENTOS (colocación / ministraciones) ────────────────────
        $this->info('════ B. OTORGAMIENTOS (fact_placements — ministraciones) ════');

        $plac = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->selectRaw('COUNT(*) as filas, SUM(amount) as total')
            ->first();

        $totalOtorgamientos = (float)$plac->total;

        $this->line(str_pad('Concepto', 40) . str_pad('Fuente/Tabla', 22) . str_pad('Columna', 22) . 'Monto');
        $this->line(str_repeat('-', 100));
        $this->line(str_pad('Ministraciones / Otorgamientos', 40) . str_pad('fact_placements', 22) . str_pad('amount', 22) . '$' . number_format($totalOtorgamientos, 2));
        $this->line(str_repeat('-', 100));
        $this->line(str_pad('TOTAL OTORGAMIENTOS', 86) . '$' . number_format($totalOtorgamientos, 2));
        $this->line("  Filas en fact_placements: " . number_format($plac->filas));
        $this->info('  Otorgamientos = dinero que volvió a prestarse. Se resta de ingresos para calcular utilidad real.');
        $this->line('');

        // ── C. GASTOS TOTALES ─────────────────────────────────────────────────
        $this->info('════ C. GASTOS TOTALES ════');

        // C1. Gastos Operativos (fact_expenses, excluyendo categorías no operativas)
        $this->info('  ── C1. GASTOS OPERATIVOS (fact_expenses) ──');

        $gastosByCat = DB::table('fact_expenses')
            ->whereIn('period_id', $dataIds)
            ->select('category', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(COALESCE(NULLIF(paid_amount,0), amount)) as total'))
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        $totalGastosOp  = 0.0;
        $totalExcluido  = 0.0;
        $envioCorpTotal = 0.0;

        $this->line(str_pad('Categoría', 40) . str_pad('Filas', 8) . str_pad('Total', 20) . 'En Gastos Totales');
        $this->line(str_repeat('-', 85));
        foreach ($gastosByCat as $g) {
            $cat      = $g->category ?? 'Sin categoría';
            $excluded = in_array($cat, self::EXCLUDED_FROM_GASTOS, true);
            $amt      = (float)$g->total;
            if ($excluded) {
                $totalExcluido += $amt;
                if ($cat === 'Envío de utilidad a corporativo') {
                    $envioCorpTotal += $amt;
                }
                $status = 'NO — distribución/financiamiento';
            } else {
                $totalGastosOp += $amt;
                $status = 'SÍ';
            }
            $this->line(str_pad(mb_substr($cat, 0, 38), 40) . str_pad($g->cnt, 8) .
                        str_pad('$' . number_format($amt, 0), 20) . $status);
        }
        $this->line(str_repeat('-', 85));
        $this->line(str_pad('TOTAL GASTOS OPERATIVOS', 68) . '$' . number_format($totalGastosOp, 2));
        $this->line('');

        // C2. Nómina (NOI: percepciones − descuentos empleado)
        $this->info('  ── C2. NÓMINA (fact_noi_movements — percepciones y descuentos) ──');
        $this->info('  Descuentos NOI = deducciones al empleado, NO gasto adicional de la empresa.');

        $noi = DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->selectRaw('concept_type, concept, COUNT(*) as cnt, SUM(amount) as total')
            ->groupBy('concept_type', 'concept')
            ->get();

        $totalPercep    = 0.0;
        $totalDesctos   = 0.0;
        $percepRows     = [];
        $desctoRows     = [];

        foreach ($noi as $n) {
            $tipo = strtolower((string)($n->concept_type ?? ''));
            $isDeduccion = str_contains($tipo, 'deduccion') || str_contains($tipo, 'descuento');
            // Only count NOI D-codes as employee deductions
            $isNOIDeduccion = $isDeduccion &&
                collect(self::NOI_DEDUCTION_CONCEPTS)->contains(fn ($c) => str_starts_with((string)$n->concept, $c));

            $amt = (float)$n->total;
            if ($isNOIDeduccion) {
                $totalDesctos += $amt;
                $desctoRows[] = $n;
            } elseif (!$isDeduccion) {
                $totalPercep += $amt;
                $percepRows[] = $n;
            }
        }

        $totalNominaNeta = $totalPercep - $totalDesctos;

        $this->line(str_pad('Tipo', 20) . str_pad('Filas', 8) . str_pad('Total', 22) . 'Rol');
        $this->line(str_repeat('-', 72));
        $this->line(str_pad('-- PERCEPCIONES (gasto de la empresa) --', 72, '-'));
        foreach ($percepRows as $n) {
            $this->line(str_pad(mb_substr((string)$n->concept_type, 0, 18), 20) .
                        str_pad($n->cnt, 8) .
                        str_pad('$' . number_format((float)$n->total, 2), 22) . 'Percepción — costo empresa');
        }
        $this->line(str_pad('TOTAL PERCEPCIONES', 28) . str_pad('', 28) . '$' . number_format($totalPercep, 2));
        $this->line('');
        $this->line(str_pad('-- DESCUENTOS NOI (al empleado, NO costo empresa adicional) --', 72, '-'));
        foreach ($desctoRows as $n) {
            $this->line(str_pad(mb_substr((string)$n->concept, 0, 18), 20) .
                        str_pad($n->cnt, 8) .
                        str_pad('$' . number_format((float)$n->total, 2), 22) . 'Descuento al empleado — no infla gasto');
        }
        $this->line(str_pad('TOTAL DESCUENTOS NOI', 28) . str_pad('', 28) . '−$' . number_format($totalDesctos, 2));
        $this->line(str_repeat('-', 72));
        $this->line(str_pad('NÓMINA NETA (Percep − Desctos)', 28) . str_pad('', 28) . '$' . number_format($totalNominaNeta, 2));
        $this->line('');

        // C3. Total Gastos
        $totalGastosTotal = $totalGastosOp + $totalNominaNeta;
        $this->line(str_pad('C1. Gastos operativos:',  45) . '$' . number_format($totalGastosOp, 2));
        $this->line(str_pad('C2. Nómina neta NOI:',    45) . '$' . number_format($totalNominaNeta, 2));
        $this->line(str_repeat('─', 70));
        $this->line(str_pad('TOTAL GASTOS TOTALES:',   45) . '$' . number_format($totalGastosTotal, 2));
        $this->line('');

        // ── D. EBITDA ─────────────────────────────────────────────────────────
        $this->info('════ D. EBITDA = Ingresos Totales − Otorgamientos − Gastos Totales ════');
        $ebitda = $totalIngresos - $totalOtorgamientos - $totalGastosTotal;

        $this->line(str_pad('Ingresos totales:',              45) . ' $' . number_format($totalIngresos, 2));
        $this->line(str_pad('Menos: Otorgamientos:',          45) . '−$' . number_format($totalOtorgamientos, 2));
        $this->line(str_pad('Menos: Gastos totales:',         45) . '−$' . number_format($totalGastosTotal, 2));
        $this->line(str_pad('  Gastos operativos:',           45) . '  $' . number_format($totalGastosOp, 2));
        $this->line(str_pad('  Nómina neta NOI:',             45) . '  $' . number_format($totalNominaNeta, 2));
        $this->line(str_repeat('═', 70));
        $color = $ebitda >= 0 ? 'green' : 'red';
        $this->line(str_pad('EBITDA / UTILIDAD:', 45) . " <fg={$color};options=bold> $" . number_format($ebitda, 2) . '</> ');
        $this->line('');

        // ── E. ENVÍO A CORPORATIVO / SOBRANTE (DESPUÉS DEL EBITDA) ───────────
        $this->info('════ E. ENVÍO UTILIDAD CORPORATIVO / SOBRANTE (no forma parte del EBITDA) ════');

        $envioRows = DB::table('fact_expenses')
            ->whereIn('period_id', $dataIds)
            ->where('category', 'Envío de utilidad a corporativo')
            ->select('concept', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(COALESCE(NULLIF(paid_amount,0),amount)) as total'))
            ->groupBy('concept')
            ->orderByDesc('total')
            ->get();

        if ($envioRows->isEmpty()) {
            $this->line('  (Sin registros de envío a corporativo en este periodo)');
        } else {
            $this->line(str_pad('Concepto', 40) . str_pad('Regs', 8) . 'Monto');
            $this->line(str_repeat('-', 70));
            foreach ($envioRows as $er) {
                $this->line(str_pad(mb_substr((string)$er->concept, 0, 38), 40) .
                            str_pad($er->cnt, 8) . '$' . number_format((float)$er->total, 2));
            }
            $this->line(str_repeat('-', 70));
        }
        $this->line(str_pad('TOTAL ENVÍO A CORPORATIVO:', 68) . '$' . number_format($envioCorpTotal, 2));
        $this->info('  Aclaración: Envío a corporativo NO reduce el EBITDA. Es distribución de utilidad ya generada.');
        $this->line('');

        // ── F. DIFERENCIA ─────────────────────────────────────────────────────
        $this->info('════ F. DIFERENCIA = EBITDA − Envío a corporativo / sobrante ════');
        $diferencia = $ebitda - $envioCorpTotal;

        $this->line(str_pad('EBITDA:',                        45) . ' $' . number_format($ebitda, 2));
        $this->line(str_pad('Menos: Envío a corporativo:',    45) . '−$' . number_format($envioCorpTotal, 2));
        $this->line(str_repeat('─', 70));
        $difColor = $diferencia >= 0 ? 'green' : 'red';
        $this->line(str_pad('DIFERENCIA:', 45) . " <fg={$difColor};options=bold> $" . number_format($diferencia, 2) . '</> ');
        $this->line('');

        if ($detail) {
            // ── DETALLE POR CATEGORÍA DE GASTO ───────────────────────────────
            $this->info('════ DETALLE POR CATEGORÍA DE GASTO ════');
            $cats = DB::table('fact_expenses')
                ->whereIn('period_id', $dataIds)
                ->select('category',
                    DB::raw('COUNT(*) as cnt'),
                    DB::raw('SUM(COALESCE(NULLIF(paid_amount,0),amount)) as total'),
                    DB::raw('SUM(CASE WHEN branch_id IS NULL THEN COALESCE(NULLIF(paid_amount,0),amount) ELSE 0 END) as sin_suc'))
                ->groupBy('category')
                ->orderByDesc('total')
                ->get();

            $this->line(str_pad('Categoría', 36) . str_pad('Regs', 7) . str_pad('Total', 18) .
                        str_pad('Sin Suc', 18) . 'En Gastos Totales');
            $this->line(str_repeat('─', 90));

            foreach ($cats as $c) {
                $cat      = $c->category ?? 'Sin categoría';
                $excluded = in_array($cat, self::EXCLUDED_FROM_GASTOS, true);
                $this->line(
                    str_pad(mb_substr($cat, 0, 34), 36) .
                    str_pad(number_format($c->cnt), 7) .
                    str_pad('$' . number_format((float)$c->total, 0), 18) .
                    str_pad('$' . number_format((float)$c->sin_suc, 0), 18) .
                    ($excluded ? 'NO — excluido' : 'SÍ')
                );
            }

            // Fondeo duplicado
            $this->line('');
            $this->info('── FONDEO: verificación de duplicado ──');
            $fondeoDetail = DB::table('fact_expenses')
                ->whereIn('period_id', $dataIds)
                ->where('category', 'Préstamos Intersucursales')
                ->select('concept', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(COALESCE(NULLIF(paid_amount,0),amount)) as total'))
                ->groupBy('concept')
                ->orderByDesc('total')
                ->get();
            foreach ($fondeoDetail as $fd) {
                $this->line("  Concepto '{$fd->concept}': {$fd->cnt} regs, $" . number_format((float)$fd->total, 2));
            }
            if ($fondeoDetail->count() > 1) {
                $amounts = $fondeoDetail->pluck('total')->map(fn ($v) => round((float)$v, 2))->toArray();
                if (min($amounts) > 0 && count(array_unique($amounts)) === 1) {
                    $this->warn("  ⚠ MISMO MONTO en todos los conceptos FONDEO → posible duplicado.");
                }
            }
        }

        if ($this->option('compare-reference')) {
            $this->compareReference($dataIds, $period->label);
        }

        return 0;
    }

    private function compareReference(array $dataIds, string $label): void
    {
        $this->line('');
        $this->info('════════════════════════════════════════════════════════════════════════');
        $this->info('  COMPARATIVO: SISTEMA ACTUAL vs REFERENCIA ABRIL 2026');
        $this->info('════════════════════════════════════════════════════════════════════════');

        // ── Valores actuales del sistema ──────────────────────────────────────
        $sysIngresos = (float) DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)->sum('total_amount');

        $sysOtorg = (float) DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)->sum('amount');

        $sysGastosOp = (float) DB::table('fact_expenses')
            ->whereIn('period_id', $dataIds)
            ->whereNotIn('category', self::EXCLUDED_FROM_GASTOS)
            ->selectRaw('SUM(COALESCE(NULLIF(paid_amount,0),amount)) as t')->value('t');

        $sysNomPercep = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)->where('concept_type', 'percepcion')->sum('amount');

        $sysNomDesctos = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)->where('concept_type', 'deduccion')
            ->whereRaw("concept REGEXP '^D(094|010|113|123|004|111)'")->sum('amount');

        $sysNomNeta    = $sysNomPercep - $sysNomDesctos;
        $sysGastosTotal = $sysGastosOp + $sysNomNeta;
        $sysEbitda      = $sysIngresos - $sysOtorg - $sysGastosTotal;

        $sysEnvio = (float) DB::table('fact_expenses')
            ->whereIn('period_id', $dataIds)
            ->where('category', 'Envío de utilidad a corporativo')
            ->selectRaw('SUM(COALESCE(NULLIF(paid_amount,0),amount)) as t')->value('t');

        $sysDif = $sysEbitda - $sysEnvio;

        // ── Valores alternativos (posibles correcciones) ──────────────────────
        // Ingresos: solo transaction=PAGO
        $ingPago = (float) DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'PAGO'")
            ->sum('total_amount');

        // Otorgamientos: monto53 sin reestructuras
        $otorgMonto53 = (float) DB::selectOne("
            SELECT SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), '$.amount_monto53')) AS DECIMAL(14,2))) as tot
            FROM fact_placements
            WHERE period_id IN (" . implode(',', $dataIds) . ")
            AND (product_name IS NULL OR product_name NOT REGEXP 'REESTRUCTURA|UNIFICACION|RECURSOS PROPIOS')
        ")->tot ?? 0;

        // Gastos sin duplicación (solo gastos_lendus = una fuente, no ambas)
        $gastosLendus = (float) DB::table('fact_expenses as fe')
            ->join('report_uploads as ru', 'fe.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('fe.period_id', $dataIds)
            ->where('ds.code', 'gastos_lendus')
            ->whereNotIn('fe.category', self::EXCLUDED_FROM_GASTOS)
            ->selectRaw('SUM(COALESCE(NULLIF(fe.paid_amount,0),fe.amount)) as t')->value('t');

        $gastosErp = (float) DB::table('fact_expenses as fe')
            ->join('report_uploads as ru', 'fe.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('fe.period_id', $dataIds)
            ->where('ds.code', 'gastos_erp')
            ->whereNotIn('fe.category', self::EXCLUDED_FROM_GASTOS)
            ->selectRaw('SUM(COALESCE(NULLIF(fe.paid_amount,0),fe.amount)) as t')->value('t');

        $gastosOpSinDup   = $gastosLendus + $gastosErp;
        $gastosAltTotal   = $gastosOpSinDup + $sysNomNeta;

        $envioLendusOnly = (float) DB::table('fact_expenses as fe')
            ->join('report_uploads as ru', 'fe.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('fe.period_id', $dataIds)
            ->where('ds.code', 'gastos_lendus')
            ->where('fe.category', 'Envío de utilidad a corporativo')
            ->selectRaw('SUM(COALESCE(NULLIF(fe.paid_amount,0),fe.amount)) as t')->value('t');

        // ── Referencias ───────────────────────────────────────────────────────
        $refIngresos = 18_332_149.55;
        $refOtorg    = 14_538_964.00;
        $refGastos   = 2_223_315.50;
        $refEnvio    = 3_076_800.00;
        $refEbitda   = $refIngresos - $refOtorg - $refGastos;
        $refDif      = $refEbitda - $refEnvio;

        $w1 = 46; $w2 = 17; $w3 = 17; $w4 = 17;
        $hdr = str_pad('Concepto', $w1) . str_pad('Sistema Actual', $w2) . str_pad('Referencia', $w3) . str_pad('Diferencia', $w4) . 'Posible causa';

        $this->line('');
        $this->line($hdr);
        $this->line(str_repeat('─', 130));

        $fmt = fn($v) => '$' . number_format($v, 2);
        $fmtD = fn($v) => ($v >= 0 ? '+' : '') . '$' . number_format($v, 2);

        $rows = [
            ['Ingresos Totales',             $sysIngresos,   $refIngresos,
             'DESCUENTO/CONDONACION incluidos (+$' . number_format($sysIngresos - $ingPago, 0) . ')'],
            ['  Solo PAGO (alt)',             $ingPago,       $refIngresos, '← filtro transaction=PAGO'],
            ['Otorgamientos',                $sysOtorg,      $refOtorg,
             'Usa desemb54; monto53 sin restr=$' . number_format($otorgMonto53, 0)],
            ['  monto53 sin restr (alt)',     $otorgMonto53,  $refOtorg, '← columna alternativa raw_payload'],
            ['Gastos Totales',               $sysGastosTotal,$refGastos, ''],
            ['  Gastos Op (actual, duplicado)',$sysGastosOp, $refGastos - $sysNomNeta,
             'gastos_lendus + gastos_lendus_excel = doble'],
            ['  Gastos Op SIN dup (alt)',     $gastosOpSinDup,$refGastos - $sysNomNeta,
             '← solo gastos_lendus + ERP'],
            ['  Nómina neta',                $sysNomNeta,    $refGastos - ($refGastos - $sysNomNeta), ''],
            ['EBITDA calculado',             $sysEbitda,     $refEbitda, ''],
            ['Envío a corporativo',          $sysEnvio,      $refEnvio,
             'EXCEDENTES duplicado (ambas fuentes); uno=$' . number_format($envioLendusOnly, 0)],
            ['  Envío solo gastos_lendus (alt)', $envioLendusOnly, $refEnvio, '← una fuente'],
            ['Diferencia (EBITDA − Envío)',  $sysDif,        $refDif, ''],
        ];

        foreach ($rows as [$lbl, $sys, $ref, $causa]) {
            $diff = $sys - $ref;
            $this->line(
                str_pad($lbl, $w1) .
                str_pad($fmt($sys), $w2) .
                str_pad($fmt($ref), $w3) .
                str_pad($fmtD($diff), $w4) .
                $causa
            );
        }

        $this->line('');
        $this->info('════ RESUMEN DE CAUSAS ════');
        $this->line('');
        $this->warn('  1. OTORGAMIENTOS (+$' . number_format($sysOtorg - $refOtorg, 0, '.', ',') . '):');
        $this->line('     Sistema usa amount/desembolso ($' . number_format($sysOtorg, 0) . ').');
        $this->line('     Referencia usa monto53/autorizado ($' . number_format($otorgMonto53, 0) . ').');
        $this->line('     Diferencia: refinanciamientos donde desemb < monto autorizado.');
        $this->line('');
        $this->warn('  2. GASTOS DUPLICADOS (+$' . number_format($sysGastosOp - $gastosOpSinDup, 0, '.', ',') . ' inflado):');
        $this->line('     gastos_lendus (PDF) y gastos_lendus_excel (Excel) son el MISMO archivo.');
        $this->line('     Se importan AMBOS → todos los gastos aparecen dos veces.');
        $this->line('     Corrección: usar solo una fuente.');
        $this->line('     Gastos op sin duplicado: $' . number_format($gastosOpSinDup, 2));
        $this->line('');
        $this->warn('  3. ENVÍO CORPORATIVO DUPLICADO (+$' . number_format($sysEnvio - $envioLendusOnly, 0, '.', ',') . ' inflado):');
        $this->line('     EXCEDENTES de gastos_lendus ($' . number_format($envioLendusOnly, 2) . ')');
        $this->line('     + gastos_lendus_excel ($' . number_format($sysEnvio - $envioLendusOnly, 2) . ') = doble.');
        $this->line('     Corrección: misma que punto 2 (eliminar fuente duplicada).');
        $this->line('');
        $this->warn('  4. INGRESOS ($' . number_format($sysIngresos - $refIngresos, 0, '.', ',') . ' de más):');
        $this->line('     Sistema suma PAGO + DESCUENTO + CONDONACION = $' . number_format($sysIngresos, 2) . '.');
        $this->line('     PAGO (cobros reales): $' . number_format($ingPago, 2) . '.');
        $this->line('     Confirmar: ¿DESCUENTO (refinanciamiento) debe contarse como ingreso?');
        $this->line('');
        $this->info('  Si se corrigen puntos 1, 2 y 3 (sin cambio a ingresos PAGO+DESCUENTO):');
        $ebitdaAlt = $sysIngresos - $otorgMonto53 - $gastosAltTotal;
        $this->line('  EBITDA corregido (aprox): $' . number_format($ebitdaAlt, 2));
        $this->info('  Si además se corrige punto 4 (solo PAGO en ingresos):');
        $ebitdaAlt2 = $ingPago - $otorgMonto53 - $gastosAltTotal;
        $this->line('  EBITDA corregido (aprox): $' . number_format($ebitdaAlt2, 2));
        $this->line('  Referencia EBITDA:        $' . number_format($refEbitda, 2));
    }
}
