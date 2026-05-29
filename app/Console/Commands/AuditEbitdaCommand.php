<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Audits EBITDA calculation: shows each component with source table, column, and amount.
 * EBITDA = Recuperación (cobranza) - Gastos Operativos - Nómina/Capital Humano
 */
class AuditEbitdaCommand extends Command
{
    protected $signature   = 'reportes:audit-ebitda
                                {period_id}
                                {--detail : show per-line registros, archivo, tabla, columna, razón}';
    protected $description = 'Auditoría EBITDA: ingresos vs gastos desglosados, separados de distribución y fondeo';

    private const EBITDA_EXCLUDED_CATS = [
        'Nómina y Capital Humano',
        'Envío de utilidad a corporativo',
        'Préstamos Intersucursales',
    ];

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
        $this->info('  Fórmula: EBITDA = Ingresos - Gastos Operativos - Nómina Neta');
        $this->info('  Ingresos = Capital + Interés + Impuesto + Cargos + Pólizas CRECE 30%');
        $this->info('  NO incluyen: Colocación, Cartera, Fondeo, Excedentes/Distribución');
        $this->line('');

        // ── A. INGRESOS (RECUPERACIÓN/COBRANZA) ──────────────────────────────
        $this->info('════ A. INGRESOS (fact_recoveries) ════');

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

        $recTotal = (float)$rec->total;
        $polizas30 = (float)$rec->polizas_crece_30;

        $this->line(str_pad('Concepto', 40) . str_pad('Fuente/Tabla', 22) .
                    str_pad('Columna', 22) . 'Monto');
        $this->line(str_repeat('-', 100));

        $items = [
            ['Capital cobrado',     'fact_recoveries', 'capital',       (float)$rec->capital],
            ['Interés cobrado',     'fact_recoveries', 'interest',      (float)$rec->interes],
            ['Impuesto cobrado',    'fact_recoveries', 'tax',           (float)$rec->impuesto],
            ['Cargos/muletas',      'fact_recoveries', 'charges',       (float)$rec->cargos],
            ['Pólizas CRECE 30%',   'fact_recoveries', 'sh_crece_share',$polizas30],
        ];

        $totalIngresos = 0.0;
        foreach ($items as [$label, $table, $col, $amt]) {
            $included = $amt > 0 ? 'Sí' : 'No (=0)';
            $this->line(str_pad($label, 40) . str_pad($table, 22) . str_pad($col, 22) . '$' . number_format($amt, 2) . "  [{$included}]");
            $totalIngresos += $amt;
        }
        $this->line(str_repeat('-', 100));
        $this->line(str_pad('TOTAL INGRESOS', 40) . str_pad('', 44) . '$' . number_format($totalIngresos, 2));

        $this->line('');
        $this->info("  Filas en fact_recoveries: " . number_format($rec->filas));
        $this->info("  Savehearts is_savehearts=true: " .
            DB::table('fact_recoveries')->whereIn('period_id', $dataIds)->where('is_savehearts', true)->count());
        $this->info("  Pólizas CRECE detectadas: " .
            DB::table('fact_recoveries')->whereIn('period_id', $dataIds)->where('savehearts_crece_share', '>', 0)->count());

        // ── B. GASTOS OPERATIVOS ──────────────────────────────────────────────
        $this->line('');
        $this->info('════ B. GASTOS OPERATIVOS (fact_expenses) ════');

        $gastosByCat = DB::table('fact_expenses')
            ->whereIn('period_id', $dataIds)
            ->select('category',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(COALESCE(NULLIF(paid_amount,0), amount)) as total'))
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        $includeInGastos = true;
        $excludedCats = ['Nómina y Capital Humano', 'Envío de utilidad a corporativo', 'Préstamos Intersucursales'];
        $totalGastosOp = 0.0;
        $totalExcluido = 0.0;

        $this->line(str_pad('Categoría', 40) . str_pad('Filas', 8) . str_pad('Total', 20) . 'Incluido en EBITDA');
        $this->line(str_repeat('-', 85));
        foreach ($gastosByCat as $g) {
            $cat = $g->category ?? 'Sin categoría';
            $excluded = in_array($cat, $excludedCats, true);
            $amt = (float)$g->total;
            if ($excluded) {
                $totalExcluido += $amt;
                $status = 'NO (separado)';
            } else {
                $totalGastosOp += $amt;
                $status = 'Sí';
            }
            $this->line(str_pad(mb_substr($cat, 0, 38), 40) . str_pad($g->cnt, 8) .
                        str_pad('$' . number_format($amt, 0), 20) . $status);
        }
        $this->line(str_repeat('-', 85));
        $this->line(str_pad('TOTAL GASTOS OP. (en EBITDA)', 40) . str_pad('', 28) . '$' . number_format($totalGastosOp, 2));
        $this->line(str_pad('EXCLUIDO (nómina, excedentes, etc.)', 40) . str_pad('', 28) . '$' . number_format($totalExcluido, 2));

        // ── C. NÓMINA ─────────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ C. NÓMINA / CAPITAL HUMANO (fact_noi_movements) ════');

        $noi = DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->selectRaw('concept_type, COUNT(*) as cnt, SUM(amount) as total')
            ->groupBy('concept_type')
            ->get();

        $totalNomina  = 0.0;
        $totalDesctos = 0.0;
        $this->line(str_pad('Tipo', 20) . str_pad('Filas', 8) . str_pad('Total', 20) . 'Rol en EBITDA');
        $this->line(str_repeat('-', 65));
        foreach ($noi as $n) {
            $isDescto = str_contains(strtolower($n->concept_type ?? ''), 'deduccion') ||
                        str_contains(strtolower($n->concept_type ?? ''), 'descuento');
            $amt = (float)$n->total;
            if ($isDescto) {
                $totalDesctos += $amt;
                $rol = 'Descuento (reduce nómina)';
            } else {
                $totalNomina += $amt;
                $rol = 'Percepción (gasto)';
            }
            $this->line(str_pad($n->concept_type ?? 'NULL', 20) . str_pad($n->cnt, 8) .
                        str_pad('$' . number_format($amt, 2), 20) . $rol);
        }
        $netNomina = $totalNomina - $totalDesctos;
        $this->line(str_repeat('-', 65));
        $this->line(str_pad('Percepciones', 20) . str_pad('', 28) . '$' . number_format($totalNomina, 2));
        $this->line(str_pad('Descuentos', 20) . str_pad('', 28) . '-$' . number_format($totalDesctos, 2));
        $this->line(str_pad('NÓMINA NETA', 20) . str_pad('', 28) . '$' . number_format($netNomina, 2));

        // ── D. CÁLCULO FINAL ──────────────────────────────────────────────────
        $this->line('');
        $this->info('════ D. CÁLCULO EBITDA ════');
        $ebitda = $totalIngresos - $totalGastosOp - $netNomina;

        $this->line(str_pad('Ingresos totales (recuperación)',  45) . '$' . number_format($totalIngresos, 2));
        $this->line(str_pad('Menos: Gastos operativos',        45) . '-$' . number_format($totalGastosOp, 2));
        $this->line(str_pad('Menos: Nómina neta NOI',          45) . '-$' . number_format($netNomina, 2));
        $this->line(str_repeat('─', 70));
        $this->line(str_pad('EBITDA',                          45) . '$' . number_format($ebitda, 2));

        $this->line('');
        $this->info('── NOTAS ──');
        $this->line('  • Pólizas CRECE 30%: $' . number_format($polizas30, 2) . ' (INCLUIDO en recuperación)');
        $this->line('  • Envío utilidad a corporativo: $' . number_format($totalExcluido, 2) . ' (NO en EBITDA, es distribución)');
        $this->line('  • Colateral/Cartera: NO se incluye en EBITDA');
        $this->line('  • Colocación: NO se incluye en EBITDA');

        if ($polizas30 === 0.0) {
            $this->warn('  ⚠ Pólizas CRECE = $0 — el Excel de cobranza no tiene filas con operación SEGURO/PÓLIZA');
        }

        // ── E. SEPARADO / NO EBITDA ───────────────────────────────────────────
        $this->line('');
        $this->info('════ E. SEPARADO — NO ENTRA EN EBITDA ════');
        $this->line(str_pad('Concepto', 40) . str_pad('Tabla', 22) . str_pad('Monto', 20) . 'Razón');
        $this->line(str_repeat('─', 100));

        $colocacion = DB::table('fact_placements')->whereIn('period_id', $dataIds)->sum('amount');
        $cartera    = DB::table('fact_portfolios')->whereIn('period_id', $dataIds)->sum('balance');

        $noEbitda = [
            ['Colocación (ministraciones)',   'fact_placements', (float)$colocacion, 'Salida de capital, no ingreso operativo'],
            ['Cartera total (saldos)',         'fact_portfolios', (float)$cartera,    'Activo financiero, no flujo de caja'],
            ['Excedentes/envío corporativo',   'fact_expenses',   (float)$totalExcluido, 'Distribución de utilidades, no gasto op.'],
            ['Fondeo/préstamos intersucursal', 'fact_expenses',   0.0,               'Financiamiento entrante — ver audit-expenses'],
        ];

        // Get fondeo amount
        $fondeoTotal = DB::table('fact_expenses')
            ->whereIn('period_id', $dataIds)
            ->where('category', 'Préstamos Intersucursales')
            ->sum(DB::raw('COALESCE(NULLIF(paid_amount,0),amount)'));
        $noEbitda[3][2] = (float)$fondeoTotal;

        foreach ($noEbitda as [$label, $table, $amt, $reason]) {
            $this->line(
                str_pad($label, 40) . str_pad($table, 22) .
                str_pad('$' . number_format($amt, 2), 20) . $reason
            );
        }

        // ── F. Detalle si --detail ────────────────────────────────────────────
        if ($detail) {
            $this->line('');
            $this->info('════ F. DETALLE POR CATEGORÍA DE GASTO ════');
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
                        str_pad('Sin Suc', 18) . str_pad('En EBITDA', 10) . 'Archivo/Fuente');
            $this->line(str_repeat('─', 100));

            foreach ($cats as $c) {
                $cat      = $c->category ?? 'Sin categoría';
                $excluded = in_array($cat, self::EBITDA_EXCLUDED_CATS, true);

                // Detect possible FONDEO duplicate
                $dupNote = '';
                if ($cat === 'Préstamos Intersucursales') {
                    $fondeoConceptos = DB::table('fact_expenses')
                        ->whereIn('period_id', $dataIds)
                        ->where('category', 'Préstamos Intersucursales')
                        ->select('concept', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(COALESCE(NULLIF(paid_amount,0),amount)) as total'))
                        ->groupBy('concept')
                        ->get();
                    if ($fondeoConceptos->count() > 1) {
                        $amounts = $fondeoConceptos->pluck('total')->map(fn($v) => round((float)$v, 0))->toArray();
                        if (count(array_unique($amounts)) < count($amounts) || count($amounts) === count(array_unique($amounts)) && count($amounts) > 1) {
                            $dupNote = ' ← POSIBLE DUPLICADO';
                        }
                    }
                }

                $this->line(
                    str_pad(mb_substr($cat, 0, 34), 36) .
                    str_pad(number_format($c->cnt), 7) .
                    str_pad('$' . number_format((float)$c->total, 0), 18) .
                    str_pad('$' . number_format((float)$c->sin_suc, 0), 18) .
                    str_pad($excluded ? 'NO' : 'SÍ', 10) .
                    'fact_expenses' . $dupNote
                );
            }

            $this->line('');
            $this->info('── FONDEO: POSIBLE DUPLICADO ──');
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
                $amounts = $fondeoDetail->pluck('total')->map(fn($v) => round((float)$v, 2))->toArray();
                if (min($amounts) > 0 && count(array_unique($amounts)) === 1) {
                    $this->warn("  ⚠ MISMO MONTO en todos los conceptos FONDEO → duplicado confirmado.");
                    $this->warn("    El total real debería ser $" . number_format((float)$amounts[0], 2) . " (no $" . number_format(array_sum($amounts), 2) . ")");
                }
            }
        }

        return 0;
    }
}
