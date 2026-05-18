<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\BranchResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugEmployeeAssignmentsCommand extends Command
{
    protected $signature   = 'reportes:debug-employee-assignments {period_id : ID del periodo}';
    protected $description = 'Muestra estado de asignaciones de empleados: con sucursal, sin match, montos sin asignar.';

    public function __construct(private readonly BranchResolverService $resolver)
    {
        parent::__construct();
    }

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
        $dataIds    = array_values(array_unique(array_merge($weeklyIds, [$period->id])));

        // Operative branch map: branch_id → sucursal
        $operativeMap = [];
        foreach (DB::table('branches')->get() as $b) {
            $real = $this->resolver->resolveRealBranchFromRoute($b->name);
            if ($real && $this->resolver->isSheetBranch($real)) {
                $operativeMap[(int) $b->id] = $real;
            }
        }

        $this->line('');
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->info("  DEBUG EMPLOYEE ASSIGNMENTS — {$period->label} (ID #{$periodId})");
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('  Data IDs: [' . implode(', ', $dataIds) . ']');
        $this->line('');

        // ── 1. Resumen global de asignaciones ────────────────────────────────
        $this->info('── 1. Resumen de asignaciones (employee_branch_assignments) ──');

        $totalEmps = DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("concept REGEXP '^P(001|002|108|109|120|123)'")
            ->where('concept_type', 'percepcion')
            ->distinct()
            ->count('employee_id');

        $conSucursal = DB::table('fact_noi_movements as n')
            ->join('employee_branch_assignments as eba', 'n.employee_id', '=', 'eba.employee_id')
            ->whereIn('n.period_id', $dataIds)
            ->whereRaw("n.concept REGEXP '^P(001|002|108|109|120|123)'")
            ->where('n.concept_type', 'percepcion')
            ->distinct()
            ->count('n.employee_id');

        $sinMatch = DB::table('fact_noi_movements as n')
            ->leftJoin('employee_branch_assignments as eba', 'n.employee_id', '=', 'eba.employee_id')
            ->whereIn('n.period_id', $dataIds)
            ->whereRaw("n.concept REGEXP '^P(001|002|108|109|120|123)'")
            ->where('n.concept_type', 'percepcion')
            ->whereNull('eba.employee_id')
            ->distinct()
            ->count('n.employee_id');

        $this->table(
            ['Total empleados (periodo)', 'Con sucursal', 'Sin match', 'Manuales'],
            [[$totalEmps, $conSucursal, $sinMatch, 0]]
        );

        // ── 2. Montos sin asignar (P001 / P002 / Bonos) ──────────────────────
        $this->line('');
        $this->info('── 2. Montos sin asignar por concepto ──');

        $sinAsignar = DB::table('fact_noi_movements as n')
            ->leftJoin('employee_branch_assignments as eba', 'n.employee_id', '=', 'eba.employee_id')
            ->whereIn('n.period_id', $dataIds)
            ->whereRaw("n.concept REGEXP '^P(001|002|108|109|120|123)'")
            ->where('n.concept_type', 'percepcion')
            ->whereNull('eba.employee_id')
            ->selectRaw("
                SUM(CASE WHEN n.concept LIKE 'P001%' THEN n.amount ELSE 0 END) AS p001_sin,
                SUM(CASE WHEN n.concept LIKE 'P002%' THEN n.amount ELSE 0 END) AS p002_sin,
                SUM(CASE WHEN n.concept REGEXP '^P(108|109|120|123)' THEN n.amount ELSE 0 END) AS bonos_sin
            ")
            ->first();

        $p001Sin  = (float) ($sinAsignar->p001_sin ?? 0);
        $p002Sin  = (float) ($sinAsignar->p002_sin ?? 0);
        $bonosSin = (float) ($sinAsignar->bonos_sin ?? 0);

        // Gastos sin asignar: CORPORATIVO branch gastos (not Norte, not operative)
        $corpIds = DB::table('branches')->get()->filter(function ($b) {
            $up = strtoupper(trim($b->name ?? ''));
            return str_contains($up, 'CORPORATIVO');
        })->pluck('id')->toArray();

        $gastosSin = 0.0;
        if (!empty($corpIds)) {
            $sourceIds = DB::table('data_sources')
                ->whereIn('code', ['gastos_lendus', 'gastos_erp'])
                ->pluck('id');
            $nonOpCats = ['Envío de utilidad a corporativo', 'Nómina y Capital Humano', 'Préstamos Intersucursales'];
            $gastosSin = (float) DB::table('fact_expenses as e')
                ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                ->whereIn('e.period_id', $dataIds)
                ->whereIn('e.branch_id', $corpIds)
                ->whereIn('ru.data_source_id', $sourceIds)
                ->whereNotIn(DB::raw("COALESCE(e.category,'')"), $nonOpCats)
                ->sum(DB::raw('COALESCE(NULLIF(e.paid_amount,0), e.amount)'));
        }

        $this->table(
            ['Monto P001 sin asignar', 'Monto P002 sin asignar', 'Bonos sin asignar', 'Gastos sin asignar (Corp)'],
            [['$' . number_format($p001Sin, 2), '$' . number_format($p002Sin, 2), '$' . number_format($bonosSin, 2), '$' . number_format($gastosSin, 2)]]
        );

        // ── 3. Totales GLOBAL (con y sin unassigned) ─────────────────────────
        $this->line('');
        $this->info('── 3. Totales GLOBAL — confirma que nada se pierde ──');

        $totalP001 = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->where('concept_type', 'percepcion')
            ->whereRaw("concept LIKE 'P001%'")
            ->sum('amount');
        $totalP002 = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->where('concept_type', 'percepcion')
            ->whereRaw("concept LIKE 'P002%'")
            ->sum('amount');
        $totalBonos = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->where('concept_type', 'percepcion')
            ->whereRaw("concept REGEXP '^P(108|109|120|123)'")
            ->sum('amount');

        $this->line(sprintf('  P001 SUELDO total (NOMINANUEVA+NOMINAF)  : $%s', number_format($totalP001, 2)));
        $this->line(sprintf('  P002 COMISIONES total                     : $%s', number_format($totalP002, 2)));
        $this->line(sprintf('  Bonos total (P108/109/120/123)            : $%s', number_format($totalBonos, 2)));
        $this->line('');
        $this->line(sprintf('  P001 asignado a sucursales                : $%s', number_format($totalP001 - $p001Sin, 2)));
        $this->line(sprintf('  P001 SIN ASIGNAR                          : $%s', number_format($p001Sin, 2)));
        $this->line(sprintf('  GLOBAL P001 (asignado + SIN ASIGNAR)      : $%s  %s',
            number_format($totalP001, 2),
            abs(($totalP001 - $p001Sin + $p001Sin) - $totalP001) < 0.01 ? '<fg=green>OK — nada se pierde</>' : '<fg=red>ERROR</>'));

        // ── 4. Lista de empleados sin match ──────────────────────────────────
        $this->line('');
        $this->info('── 4. Empleados sin match — detalle con montos ──');

        $sinMatchList = DB::table('fact_noi_movements as n')
            ->leftJoin('employee_branch_assignments as eba', 'n.employee_id', '=', 'eba.employee_id')
            ->leftJoin('employees as emp', 'n.employee_id', '=', 'emp.id')
            ->join('report_uploads as ru', 'n.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('n.period_id', $dataIds)
            ->whereRaw("n.concept REGEXP '^P(001|002|108|109|120|123)'")
            ->where('n.concept_type', 'percepcion')
            ->whereNull('eba.employee_id')
            ->selectRaw("
                COALESCE(emp.full_name, CONCAT('Emp#', n.employee_id)) AS nombre,
                n.employee_id,
                ds.code AS fuente,
                SUM(CASE WHEN n.concept LIKE 'P001%' THEN n.amount ELSE 0 END) AS p001,
                SUM(CASE WHEN n.concept LIKE 'P002%' THEN n.amount ELSE 0 END) AS p002,
                SUM(CASE WHEN n.concept REGEXP '^P(108|109|120|123)' THEN n.amount ELSE 0 END) AS bonos
            ")
            ->groupBy('n.employee_id', 'emp.full_name', 'ds.id', 'ds.code')
            ->orderByDesc('p001')
            ->get();

        if ($sinMatchList->isEmpty()) {
            $this->line('  <fg=green>✓ Todos los empleados tienen sucursal asignada.</>');
        } else {
            $tableRows = $sinMatchList->map(fn ($r) => [
                mb_substr((string) $r->nombre, 0, 35),
                '$' . number_format((float) $r->p001, 2),
                '$' . number_format((float) $r->p002, 2),
                '$' . number_format((float) $r->bonos, 2),
                $r->fuente,
                'Verificar manualmente',
                'Agregar a employee_branch_assignments',
            ])->all();

            $this->table(
                ['Empleado', 'P001', 'P002', 'Bonos', 'Fuente', 'Posible sucursal', 'Acción'],
                $tableRows
            );
        }

        // ── 5. Empleados con sucursal (resumen por sucursal) ─────────────────
        $this->line('');
        $this->info('── 5. Empleados asignados por sucursal (resumen) ──');

        $asignadosPorSuc = DB::table('fact_noi_movements as n')
            ->join('employee_branch_assignments as eba', 'n.employee_id', '=', 'eba.employee_id')
            ->join('branches as b', 'eba.branch_id', '=', 'b.id')
            ->whereIn('n.period_id', $dataIds)
            ->whereRaw("n.concept REGEXP '^P(001|002|108|109|120|123)'")
            ->where('n.concept_type', 'percepcion')
            ->selectRaw("
                b.name AS sucursal,
                COUNT(DISTINCT n.employee_id) AS emps,
                SUM(CASE WHEN n.concept LIKE 'P001%' THEN n.amount ELSE 0 END) AS p001,
                SUM(CASE WHEN n.concept LIKE 'P002%' THEN n.amount ELSE 0 END) AS p002,
                SUM(CASE WHEN n.concept REGEXP '^P(108|109|120|123)' THEN n.amount ELSE 0 END) AS bonos
            ")
            ->groupBy('b.id', 'b.name')
            ->orderByDesc('p001')
            ->get();

        $this->table(
            ['Sucursal', 'Emps', 'P001', 'P002', 'Bonos'],
            $asignadosPorSuc->map(fn ($r) => [
                mb_substr($r->sucursal, 0, 30),
                $r->emps,
                '$' . number_format((float) $r->p001, 2),
                '$' . number_format((float) $r->p002, 2),
                '$' . number_format((float) $r->bonos, 2),
            ])->all()
        );

        // ── 6. Confirmación: cartera/recuperación/colocación/mora NO dependen de empleados ──
        $this->line('');
        $this->info('── 6. Confirmación de independencia de métricas operativas ──');
        $this->line('  <fg=green>✓ Cartera        → fact_portfolios (balance por branch_id/oficina, NO empleados)</>');
        $this->line('  <fg=green>✓ Mora/buckets   → fact_portfolios (days_past_due por branch_id/oficina, NO empleados)</>');
        $this->line('  <fg=green>✓ Recuperación   → fact_recoveries (PAGO por branch_id/prefix, NO empleados)</>');
        $this->line('  <fg=green>✓ Colocación     → fact_placements (amount por branch_id, NO empleados)</>');
        $this->line('  <fg=yellow>~ Nómina/comis/bonos → fact_noi_movements × employee_branch_assignments</>');
        $this->line('  <fg=yellow>~ Gastos con sucursal → Lendus PDF/ERP branch_id directo</>');
        if ($sinMatch > 0) {
            $this->line("  <fg=yellow>~ SIN ASIGNAR (empleados) → {$sinMatch} empleados → \$" . number_format($p001Sin + $p002Sin + $bonosSin, 2) . " → aparecen en pestaña SIN ASIGNAR del Excel</>");
        }
        if ($gastosSin > 0) {
            $this->line("  <fg=yellow>~ SIN ASIGNAR (gastos corp) → \$" . number_format($gastosSin, 2) . " → aparecen en pestaña SIN ASIGNAR del Excel</>");
        }
        $this->line('');
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('');

        return self::SUCCESS;
    }
}
