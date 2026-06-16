<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditPayrollEmployeesCommand extends Command
{
    protected $signature   = 'reportes:audit-payroll-employees
                                {period_id}
                                {--compare-reference : comparar totales contra referencia Abril 2026}';
    protected $description = 'Auditoría nómina por empleado: detecta extra, missing y clasificación incorrecta';

    // Reasignaciones manuales de sucursal por nombre normalizado
    private const OVERRIDES = [
        'MONICA ANA KAREN GARCIA CANCINO' => 'TULA',
        'VICTOR ANGEL CANO GARCIA'        => 'TENANGO DEL VALLE',
    ];

    // Conceptos que deben eliminarse del total global (referencia los trae en $0)
    private const ZERO_GLOBAL_CONCEPTS = [
        'PENSION ALIMENTICIA',
        'PENSIÓN ALIMENTICIA',
    ];

    // Conceptos que no deben aparecer en la radiografía
    private const INVALID_CONCEPTS = [
        'OTROS CONCEPTOS NÓMINA',
        'OTROS CONCEPTOS NOMINA',
    ];

    // Referencia Abril 2026
    private const REF_TOTAL = 2_501_589.43;
    private const REF_DELTAS = [
        // [concepto => diferencia conocida explicada]
        'PENSION ALIMENTICIA'     => 2_646.32,   // debe ser $0 en total global
        'FINANCIAMIENTO MOTOS'    => 423.00,      // 1 registro extra
        'OTROS CONCEPTOS NOMINA'  => 334.00,      // no debe aparecer
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

        $this->line('');
        $this->info("════════════════════════════════════════════════════════════════════");
        $this->info("  AUDITORÍA NÓMINA POR EMPLEADO — {$period->label} (ID {$period->id})");
        $this->info("════════════════════════════════════════════════════════════════════");

        // ── A. Conteo por concepto ─────────────────────────────────────────────
        $this->line('');
        $this->info('════ A. TOTALES POR CONCEPTO ════');

        $byConcept = DB::table('fact_noi_movements as n')
            ->leftJoin('branches as b', 'n.branch_id', '=', 'b.id')
            ->whereIn('n.period_id', $dataIds)
            ->whereNotNull('n.employee_id')
            ->select(
                DB::raw('UPPER(COALESCE(n.concept,"Sin concepto")) as concept'),
                DB::raw('LOWER(COALESCE(n.concept_type,"")) as concept_type'),
                DB::raw('SUM(n.amount) as total'),
                DB::raw('COUNT(*) as cnt')
            )
            ->groupBy(DB::raw('UPPER(COALESCE(n.concept,"Sin concepto"))'), DB::raw('LOWER(COALESCE(n.concept_type,""))'))
            ->orderByDesc('total')
            ->get();

        $this->line(str_pad('Concepto', 38) . str_pad('Tipo', 14) . str_pad('Total', 18) . 'Regs');
        $this->line(str_repeat('─', 80));

        $totalSystem = 0.0;
        foreach ($byConcept as $row) {
            $concepto = (string)$row->concept;
            $monto    = (float)$row->total;

            $flag = '';
            if (in_array($concepto, array_map('strtoupper', self::ZERO_GLOBAL_CONCEPTS))) {
                $flag = ' ⚠DEBE SER $0';
            }
            if (in_array($concepto, array_map('strtoupper', self::INVALID_CONCEPTS))) {
                $flag = ' ✗NO DEBE APARECER';
            }

            $this->line(
                str_pad(mb_substr($concepto, 0, 36), 38) .
                str_pad($row->concept_type, 14) .
                str_pad('$' . number_format($monto, 2), 18) .
                $row->cnt .
                $flag
            );
            $totalSystem += $monto;
        }

        $this->line(str_repeat('─', 80));
        $this->line("  " . str_pad('TOTAL SISTEMA', 38) . str_pad('', 14) . '$' . number_format($totalSystem, 2));

        // ── B. Comparativo vs referencia ──────────────────────────────────────
        if ($this->option('compare-reference')) {
            $this->line('');
            $this->info('════ B. COMPARATIVO VS REFERENCIA ABRIL 2026 ════');

            $diff  = $totalSystem - self::REF_TOTAL;
            $sign  = $diff >= 0 ? '+' : '';
            $match = abs($diff) < 1 ? '✓ EXACTO' : (abs($diff) < 5_000 ? '~ CASI OK' : '✗ DIFERENCIA');

            $this->line("  Referencia:  \$" . number_format(self::REF_TOTAL, 2));
            $this->line("  Sistema:     \$" . number_format($totalSystem, 2));
            $msg = "  Diferencia:  {$sign}\$" . number_format(abs($diff), 2) . " [{$match}]";
            if ($match === '✓ EXACTO') $this->info($msg);
            else $this->warn($msg);

            $this->line('');
            $this->warn('  Diferencias conocidas con referencia:');
            $knownDelta = 0.0;
            foreach (self::REF_DELTAS as $concepto => $delta) {
                $this->warn("  → {$concepto}: +\$" . number_format($delta, 2));
                $knownDelta += $delta;
            }
            $this->warn("  Total ajuste conocido: +\$" . number_format($knownDelta, 2));
            $unexplained = $diff - $knownDelta;
            if (abs($unexplained) > 1) {
                $this->warn("  ⚠ Diferencia no explicada: \$" . number_format(abs($unexplained), 2));
            } else {
                $this->info("  ✓ Toda la diferencia está explicada por los deltas conocidos.");
            }
        }

        // ── C. Por sucursal ───────────────────────────────────────────────────
        $this->line('');
        $this->info('════ C. TOTALES POR SUCURSAL ════');

        $byBranch = DB::table('fact_noi_movements as n')
            ->leftJoin('branches as b', 'n.branch_id', '=', 'b.id')
            ->leftJoin('employees as e', 'n.employee_id', '=', 'e.id')
            ->whereIn('n.period_id', $dataIds)
            ->whereNotNull('n.employee_id')
            ->select(
                DB::raw('UPPER(COALESCE(b.name,"SIN SUCURSAL")) as sucursal'),
                DB::raw('SUM(n.amount) as total'),
                DB::raw('COUNT(DISTINCT n.employee_id) as empleados'),
                DB::raw('COUNT(*) as movimientos')
            )
            ->groupBy(DB::raw('UPPER(COALESCE(b.name,"SIN SUCURSAL"))'))
            ->orderByDesc('total')
            ->get();

        $this->line(str_pad('Sucursal', 28) . str_pad('Total', 18) . str_pad('Empleados', 12) . 'Movs');
        $this->line(str_repeat('─', 68));
        foreach ($byBranch as $row) {
            $sucursal = (string)$row->sucursal;
            $override = '';
            foreach (self::OVERRIDES as $nombre => $newBranch) {
                // note: overrides checked per-employee, not per-branch aggregate
            }
            $this->line(
                str_pad(mb_substr($sucursal, 0, 26), 28) .
                str_pad('$' . number_format((float)$row->total, 2), 18) .
                str_pad((string)$row->empleados, 12) .
                $row->movimientos
            );
        }

        // ── D. Reasignaciones activas ─────────────────────────────────────────
        $this->line('');
        $this->info('════ D. REASIGNACIONES DE SUCURSAL ACTIVAS ════');
        $this->line('  Empleados cuya sucursal fue corregida manualmente:');
        foreach (self::OVERRIDES as $nombre => $branchOverride) {
            $emp = DB::table('employees')
                ->whereRaw("UPPER(TRIM(name)) = ?", [strtoupper(trim($nombre))])
                ->first();

            if ($emp) {
                $movs = DB::table('fact_noi_movements')
                    ->whereIn('period_id', $dataIds)
                    ->where('employee_id', $emp->id)
                    ->sum('amount');
                $this->line("  → {$nombre}: \$" . number_format((float)$movs, 2) . " → asignado a {$branchOverride}");
            } else {
                $this->warn("  → {$nombre}: no encontrado en tabla employees");
            }
        }

        // ── E. Conceptos problemáticos ────────────────────────────────────────
        $this->line('');
        $this->info('════ E. CONCEPTOS A CORREGIR ════');

        foreach (self::ZERO_GLOBAL_CONCEPTS as $concept) {
            $rows = DB::table('fact_noi_movements as n')
                ->leftJoin('employees as e', 'n.employee_id', '=', 'e.id')
                ->leftJoin('branches as b', 'n.branch_id', '=', 'b.id')
                ->whereIn('n.period_id', $dataIds)
                ->whereNotNull('n.employee_id')
                ->whereRaw("UPPER(COALESCE(n.concept,'')) = ?", [strtoupper($concept)])
                ->select(
                    DB::raw('COALESCE(e.name,"—") as nombre'),
                    DB::raw('COALESCE(b.name,"—") as sucursal'),
                    DB::raw('SUM(n.amount) as total')
                )
                ->groupBy('n.employee_id', 'e.name', 'b.name')
                ->get();

            $total = $rows->sum(fn ($r) => (float)$r->total);
            if ($total > 0) {
                $this->warn("  ✗ {$concept}: total \$" . number_format($total, 2) . " (debe ser \$0.00 global en referencia)");
                foreach ($rows as $r) {
                    $this->line("      - {$r->nombre} ({$r->sucursal}): \$" . number_format((float)$r->total, 2));
                }
            }
        }

        foreach (self::INVALID_CONCEPTS as $concept) {
            $cnt = DB::table('fact_noi_movements')
                ->whereIn('period_id', $dataIds)
                ->whereNotNull('employee_id')
                ->whereRaw("UPPER(COALESCE(concept,'')) = ?", [strtoupper($concept)])
                ->count();
            if ($cnt > 0) {
                $this->warn("  ✗ {$concept}: {$cnt} registros encontrados (concepto inválido, no debe aparecer en radiografía)");
            }
        }

        // ── F. Financiamiento Motos ───────────────────────────────────────────
        $this->line('');
        $this->info('════ F. FINANCIAMIENTO MOTOS (1 registro extra en sistema) ════');

        $motoRows = DB::table('fact_noi_movements as n')
            ->leftJoin('employees as e', 'n.employee_id', '=', 'e.id')
            ->leftJoin('branches as b', 'n.branch_id', '=', 'b.id')
            ->whereIn('n.period_id', $dataIds)
            ->whereNotNull('n.employee_id')
            ->whereRaw("UPPER(COALESCE(n.concept,'')) LIKE '%FINANCIAMIENTO%MOTO%'")
            ->select(
                DB::raw('COALESCE(e.name,"—") as nombre'),
                DB::raw('COALESCE(b.name,"—") as sucursal'),
                'n.amount',
                'n.period_id'
            )
            ->get();

        if ($motoRows->isEmpty()) {
            $this->line("  (no se encontraron registros de Financiamiento Motos)");
        } else {
            $this->line(str_pad('Empleado', 34) . str_pad('Sucursal', 20) . 'Monto');
            $this->line(str_repeat('─', 65));
            foreach ($motoRows as $r) {
                $this->line("  " . str_pad($r->nombre, 34) . str_pad($r->sucursal, 20) . '$' . number_format((float)$r->amount, 2));
            }
            $this->line("  Total: \$" . number_format($motoRows->sum(fn ($r) => (float)$r->amount), 2) . " — referencia tiene \$" . number_format(self::REF_DELTAS['FINANCIAMIENTO MOTOS'], 2) . " menos");
        }

        return 0;
    }
}
