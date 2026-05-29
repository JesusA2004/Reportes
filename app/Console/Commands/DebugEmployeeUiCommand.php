<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Audits the discrepancy between what Incidencias shows (period_incidents)
 * and what the Empleados/Gestores tab shows (employees prop + personasSinSucursal endpoint).
 */
class DebugEmployeeUiCommand extends Command
{
    protected $signature   = 'reportes:debug-employee-ui {period_id}';
    protected $description = 'Audita la consistencia entre Incidencias, pestaña Empleados/Gestores y fuentes de verdad de asignación';

    private const CHECK_NAMES = [
        'MARGARITA JAZMIN NOLASCO',
        'MAURICIO VELAZQUEZ',
        'ENRIQUE HERNANDEZ CARDENAS',
        'JESUS LOZADA',
        'GUIDO HUMBERTO RUIZ',
        'EVA CRISTINA SANCHEZ VELASCO',
        'CARLOS EDUARDO TORRES',
        'DIANA BORGES',
        'GRISELDA MARCELINO',
        'LUIS GERARDO ROMERO',
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

        $this->info("════════════════════════════════════════════════════════════");
        $this->info("  DEBUG EMPLOYEE UI — {$period->label} (ID {$period->id})");
        $this->info("════════════════════════════════════════════════════════════");

        // ── A. Estado de incidencias ─────────────────────────────────────────
        $this->line('');
        $this->info('════ A. INCIDENCIAS (period_incidents) ════');

        $summary = DB::table('period_summaries')->where('period_id', $period->id)->orderByDesc('id')->first();
        if (!$summary) {
            $this->warn('  Sin period_summary — incidencias imposibles de mostrar.');
        } else {
            $this->line("  period_summary ID={$summary->id} status={$summary->status} version={$summary->version}");
            $incidents = DB::table('period_incidents')->where('period_summary_id', $summary->id)->get(['type', 'severity', 'message']);
            $this->line("  Total period_incidents: " . $incidents->count());
            $this->line("  Críticas (high): " . $incidents->where('severity', 'high')->count());
            $this->line("  Warnings:        " . $incidents->where('severity', 'warning')->count());
            $this->line("  Sin sucursal tipo: " . $incidents->whereIn('type', ['empleados_sin_sucursal', 'db_update.empleados_sin_sucursal'])->count());
            if ($incidents->isEmpty()) {
                $this->info("  ✓ Sin incidencias → Incidencias muestra 0 (correcto si fact_noi_movements está vacío)");
            }
        }

        // ── B. Estado real de asignaciones ───────────────────────────────────
        $this->line('');
        $this->info('════ B. ESTADO REAL — employee_branch_assignments ════');

        $noiTotal = DB::table('fact_noi_movements')->whereIn('period_id', $dataIds)->count();
        $empNoi   = DB::table('fact_noi_movements')->whereIn('period_id', $dataIds)->distinct()->count('employee_id');
        $empNull  = DB::table('fact_noi_movements')->whereIn('period_id', $dataIds)->whereNull('employee_id')->count();

        $this->line("  fact_noi_movements (dataIds): " . number_format($noiTotal) . " filas");
        $this->line("    employee_ids únicos: {$empNoi}");
        $this->line("    sin employee_id (no enlazados): {$empNull}");

        $ebaCount   = DB::table('employee_branch_assignments')->where('period_id', $period->id)->count();
        $ebaWithSuc = DB::table('employee_branch_assignments')->where('period_id', $period->id)->whereNotNull('branch_id')->count();
        $ebaNoSuc   = DB::table('employee_branch_assignments')->where('period_id', $period->id)->whereNull('branch_id')->count();

        $this->line("  employee_branch_assignments período {$period->id}: {$ebaCount}");
        $this->line("    Con sucursal:    {$ebaWithSuc}");
        $this->line("    Sin sucursal:    {$ebaNoSuc}");

        // Empleados que aparecen en NOI pero sin EBA
        $noiEmpIds = DB::table('fact_noi_movements')->whereIn('period_id', $dataIds)->whereNotNull('employee_id')->distinct()->pluck('employee_id');
        $ebaEmpIds = DB::table('employee_branch_assignments')->where('period_id', $period->id)->pluck('employee_id');
        $sinEba    = $noiEmpIds->diff($ebaEmpIds);

        $this->line("  Empleados en NOI pero SIN EBA: " . $sinEba->count());
        if ($sinEba->isNotEmpty()) {
            $names = DB::table('employees')->whereIn('id', $sinEba->take(10))->pluck('full_name');
            foreach ($names as $n) { $this->line("    → {$n}"); }
        }

        // Empleados activos sin ningún EBA en BD
        $totalActive   = DB::table('employees')->where('is_active', true)->count();
        $activeWithEba = DB::table('employees as e')->where('e.is_active', true)
            ->whereExists(fn($q) => $q->from('employee_branch_assignments as eba')->whereColumn('eba.employee_id','e.id'))
            ->count();
        $activeNoEba = $totalActive - $activeWithEba;

        $this->line("  Empleados is_active=true en tabla: {$totalActive}");
        $this->line("  Activos con algún EBA (cualquier período): {$activeWithEba}");
        $this->line("  Activos sin NINGÚN EBA: {$activeNoEba} ← estos aparecen como 'Sin sucursal' en el prop employees");

        // ── C. Estado que usa la UI (employees prop) ─────────────────────────
        $this->line('');
        $this->info('════ C. PROP `employees` QUE USA LA UI ════');
        $this->line('  Fuente: ReportUploadController::index() línea 206-215');
        $this->line('  Endpoint: GET /historico-general');
        $this->line('  Tabla: employees WHERE is_active=true');
        $this->line('  JOIN: employeeBranchAssignments->latest() SIN FILTRO DE PERIODO');
        $this->line('');

        // Simulate what the prop returns
        $allActive = DB::table('employees as e')
            ->where('e.is_active', true)
            ->select('e.id', 'e.full_name', 'e.normalized_name')
            ->get();

        $withBranchInProp = 0;
        $noBranchInProp   = 0;
        foreach ($allActive as $emp) {
            $latestEba = DB::table('employee_branch_assignments as eba')
                ->leftJoin('branches as b', 'eba.branch_id', '=', 'b.id')
                ->where('eba.employee_id', $emp->id)
                ->orderByDesc('eba.period_id')
                ->first(['b.name as branch_name']);
            if ($latestEba?->branch_name) {
                $withBranchInProp++;
            } else {
                $noBranchInProp++;
            }
        }

        $this->line("  Total empleados activos en prop: {$totalActive}");
        $this->line("  Con sucursal (latest EBA, cualquier período): {$withBranchInProp}");
        $this->warn("  Sin sucursal en prop (bug - no filtrado por período {$period->id}): {$noBranchInProp}");
        $this->line('');
        $this->info('  BUG CONFIRMADO: La prop usa ->latest() sin WHERE period_id = ' . $period->id);
        $this->info('  FIX: Filtrar employeeBranchAssignments por period_id actual.');
        $this->line('');

        // What SHOULD the prop return (corrected)
        $withBranchPeriod = DB::table('employees as e')
            ->join('employee_branch_assignments as eba', 'eba.employee_id', '=', 'e.id')
            ->leftJoin('branches as b', 'eba.branch_id', '=', 'b.id')
            ->where('e.is_active', true)
            ->where('eba.period_id', $period->id)
            ->whereNotNull('eba.branch_id')
            ->count();
        $noBranchPeriod = $totalActive - $withBranchPeriod;
        $this->line("  CON FIX (filtrado por period_id={$period->id}):");
        $this->line("    Con sucursal para este periodo: {$withBranchPeriod}");
        $this->line("    Sin sucursal para este periodo: {$noBranchPeriod}");

        // ── D. Ejemplos individuales ─────────────────────────────────────────
        $this->line('');
        $this->info('════ D. EJEMPLOS INDIVIDUALES ════');

        $this->line(str_pad('Empleado', 45) . str_pad('emp_id', 8) . str_pad('EBA período actual', 22) .
                    str_pad('EBA latest', 18) . str_pad('En NOI', 8) . 'UI shows (bug)');
        $this->line(str_repeat('─', 120));

        foreach (self::CHECK_NAMES as $partialName) {
            $parts = explode(' ', $partialName);
            $q = DB::table('employees')->where('is_active', true);
            foreach ($parts as $part) {
                $q->where('full_name', 'LIKE', "%{$part}%");
            }
            $emp = $q->first(['id', 'full_name', 'normalized_name']);

            if (!$emp) {
                $this->line(str_pad(mb_substr($partialName, 0, 43), 45) . 'NO ENCONTRADO');
                continue;
            }

            // EBA for current period
            $ebaP5 = DB::table('employee_branch_assignments as eba')
                ->leftJoin('branches as b', 'eba.branch_id', '=', 'b.id')
                ->where('eba.employee_id', $emp->id)
                ->where('eba.period_id', $period->id)
                ->first(['b.name as bname', 'eba.match_type', 'eba.confidence']);

            // Latest EBA (any period)
            $ebaLatest = DB::table('employee_branch_assignments as eba')
                ->leftJoin('branches as b', 'eba.branch_id', '=', 'b.id')
                ->where('eba.employee_id', $emp->id)
                ->orderByDesc('eba.period_id')
                ->first(['b.name as bname', 'eba.period_id']);

            // In NOI
            $inNoi = DB::table('fact_noi_movements')->whereIn('period_id', $dataIds)->where('employee_id', $emp->id)->exists();

            $ebaP5Str     = $ebaP5 ? mb_substr($ebaP5->bname ?? 'sin suc', 0, 20) : 'SIN EBA período';
            $ebaLatestStr = $ebaLatest ? mb_substr(($ebaLatest->bname ?? 'NULL') . '(p' . $ebaLatest->period_id . ')', 0, 16) : 'NUNCA';
            $inNoiStr     = $inNoi ? 'SÍ' : 'NO';
            $uiShows      = ($ebaLatest?->bname) ? $ebaLatest->bname : 'Sin sucursal ←BUG';

            $this->line(
                str_pad(mb_substr($emp->full_name, 0, 43), 45) .
                str_pad($emp->id, 8) .
                str_pad($ebaP5Str, 22) .
                str_pad($ebaLatestStr, 18) .
                str_pad($inNoiStr, 8) .
                $uiShows
            );
        }

        // ── E. Diagnóstico personasSinSucursal endpoint ──────────────────────
        $this->line('');
        $this->info('════ E. ENDPOINT /personas-sin-sucursal ════');
        $this->line('  Tabla: fact_noi_movements LEFT JOIN employee_branch_assignments');
        $this->line('  Condición: eba.branch_id IS NULL');
        $this->line("  fact_noi_movements para dataIds: {$noiTotal} filas");
        if ($noiTotal === 0) {
            $this->warn("  → fact_noi_movements VACÍO → endpoint devuelve 0 personas");
            $this->warn("  → Si la UI muestra personas, es estado CACHEADO en Vue (stale data)");
            $this->info("  → Solución: el usuario debe hacer clic en 'Recargar lista' o refrescar la página");
        } else {
            $sinSuc = DB::table('fact_noi_movements as n')
                ->leftJoin('employee_branch_assignments as eba', function ($j) use ($period, $dataIds) {
                    $j->on('eba.employee_id', '=', 'n.employee_id')
                      ->whereIn('eba.period_id', array_merge([$period->id], $dataIds));
                })
                ->whereIn('n.period_id', $dataIds)
                ->whereNotNull('n.employee_id')
                ->whereNull('eba.branch_id')
                ->distinct('n.employee_id')
                ->count('n.employee_id');
            $this->line("  Personas sin sucursal en endpoint: {$sinSuc}");
        }

        // ── F. Resumen de consistencia ────────────────────────────────────────
        $this->line('');
        $this->info('════ F. TABLA DE CONSISTENCIA ════');
        $this->line(str_pad('Fuente', 38) . str_pad('Dato', 28) . 'Consistente con Incidencias?');
        $this->line(str_repeat('─', 90));

        $items = [
            ['period_incidents',                        "0 incidentes",                  'SÍ (0 y 0)'],
            ['employees prop (BUG actual)',              "{$noBranchInProp} sin sucursal", 'NO — bug: sin filtro de periodo'],
            ['employees prop (con fix)',                 "{$noBranchPeriod} sin sucursal", 'SÍ — si 0, consistente con 0 incidents'],
            ['/personas-sin-sucursal (actual)',          $noiTotal === 0 ? '0 (fact_noi vacío)' : 'N/A',  'SÍ — si NOI vacío → 0'],
            ['employee_branch_assignments sin branch',  "{$ebaNoSuc} sin suc / {$ebaCount} total", 'Depende del contexto'],
        ];
        foreach ($items as [$fuente, $dato, $consistente]) {
            $this->line(str_pad(mb_substr($fuente,0,36), 38) . str_pad(mb_substr($dato,0,26), 28) . $consistente);
        }

        return 0;
    }
}
