<?php

namespace App\Console\Commands;

use App\Models\Period;
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
                                {--by-branch : show breakdown by branch}';
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

        // ── Por sucursal ─────────────────────────────────────────────────────
        if ($this->option('by-branch')) {
            $this->line('');
            $this->info('════ DESGLOSE POR SUCURSAL ════');

            $byBranch = DB::table('fact_recoveries as fr')
                ->leftJoin('branches as b', 'fr.branch_id', '=', 'b.id')
                ->whereIn('fr.period_id', $dataIds)
                ->when($filterProd, fn($q) => $q->whereRaw("UPPER(COALESCE(fr.product_name,'')) LIKE ?", ["%{$filterProd}%"]))
                ->select(
                    DB::raw('COALESCE(b.name,"Sin sucursal") as sucursal'),
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

            $this->line(str_pad('Sucursal', 28) . str_pad('Regs', 7) . str_pad('Capital', 16) .
                        str_pad('Interés', 14) . str_pad('Impuesto', 13) . str_pad('Cargos', 13) . 'Total');
            $this->line(str_repeat('─', 100));

            foreach ($byBranch as $br) {
                $marker = ($br->sucursal === 'Sin sucursal') ? ' ⚠' : '';
                $this->line(
                    str_pad(mb_substr($br->sucursal . $marker, 0, 26), 28) .
                    str_pad(number_format($br->cnt), 7) .
                    str_pad('$' . number_format((float)$br->capital, 0), 16) .
                    str_pad('$' . number_format((float)$br->interes, 0), 14) .
                    str_pad('$' . number_format((float)$br->impuesto, 0), 13) .
                    str_pad('$' . number_format((float)$br->cargos, 0), 13) .
                    '$' . number_format((float)$br->total, 0)
                );
            }

            $nobranchTotal = DB::table('fact_recoveries')
                ->whereIn('period_id', $dataIds)
                ->whereNull('branch_id')
                ->sum('total_amount');
            if ($nobranchTotal > 0) {
                $this->warn("  ⚠ Recuperación sin sucursal: $" . number_format((float)$nobranchTotal, 2) .
                             " — puede estar afectando totales por sucursal.");
            }
        }

        return 0;
    }
}
