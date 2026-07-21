<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Period;
use Illuminate\Support\Facades\DB;

/**
 * Roster canónico de empleados activos por periodo — fuente ÚNICA para derivar
 * Rotación de Personal e IMSS patronal desde NOI Nómina + NOI Nómina Fiscal.
 * NUNCA usa el reporte de empleados Lendus (usuarios irregulares/bajas mal
 * reflejadas). La sucursal se resuelve vía employee_branch_assignments del
 * periodo (ya poblado por EmployeeBranchAutoMatchService antes de que este
 * servicio corra), con fallback a la asignación histórica más reciente.
 *
 * Deduplicación: NOI normal y NOI fiscal frecuentemente crean DOS registros
 * `Employee` distintos para la misma persona real (distinto employee_code por
 * archivo). La identidad real del roster es `nombre_normalizado` — un mismo
 * nombre normalizado con dos employee_id (uno de cada archivo) cuenta como
 * UN solo colaborador, nunca dos. Sin este paso, la plantilla queda inflada
 * artificialmente (confirmado contra Junio 2026: 173 employee_id vs 125
 * nombres únicos).
 *
 * Idempotente: siempre borra el roster del periodo antes de reinsertar, para
 * que reprocesar no duplique filas.
 */
class PeriodEmployeeRosterService
{
    public function __construct(
        private readonly BranchResolverService $branchResolver,
        private readonly PersonIdentityResolverService $identityResolver,
    ) {}

    /**
     * @return array{active_count:int,altas:int,bajas:int,sin_sucursal:int,prev_period_id:?int,duplicados_fusionados:int}
     */
    public function buildForPeriod(Period $period): array
    {
        if (!$period->isMonthly()) {
            throw new \InvalidArgumentException('El roster de empleados solo se construye para periodos mensuales.');
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(empty($weeklyIds) ? [] : $weeklyIds, [$period->id])));

        // ── 1. Empleados (employee_id crudo) con movimiento NOI este periodo, por fuente ──
        $movementRows = DB::table('fact_noi_movements as fnm')
            ->join('report_uploads as ru', 'fnm.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('fnm.period_id', $dataIds)
            ->whereNotNull('fnm.employee_id')
            ->whereIn('ds.code', ['noi_nomina', 'noi_nomina_fiscal'])
            ->select('fnm.employee_id', 'ds.code as source_code')
            ->distinct()
            ->get();

        $byEmployeeId = [];
        foreach ($movementRows as $row) {
            $eid = (int) $row->employee_id;
            $byEmployeeId[$eid] ??= ['normal' => false, 'fiscal' => false];
            if ($row->source_code === 'noi_nomina') {
                $byEmployeeId[$eid]['normal'] = true;
            } elseif ($row->source_code === 'noi_nomina_fiscal') {
                $byEmployeeId[$eid]['fiscal'] = true;
            }
        }

        if (empty($byEmployeeId)) {
            DB::table('period_employee_rosters')->where('period_id', $period->id)->delete();
            return ['active_count' => 0, 'altas' => 0, 'bajas' => 0, 'sin_sucursal' => 0, 'prev_period_id' => null, 'duplicados_fusionados' => 0];
        }

        $rawEmployeeIds = array_keys($byEmployeeId);
        $employees      = Employee::query()->whereIn('id', $rawEmployeeIds)->get()->keyBy('id');

        // ── 2. Fusionar por nombre_normalizado — misma persona real, un solo roster row ──
        $byIdentity = []; // normalized_name => ['employee_ids'=>[], 'normal'=>bool,'fiscal'=>bool,'nombre_original'=>string]
        $duplicadosFusionados = 0;
        foreach ($byEmployeeId as $eid => $flags) {
            $emp = $employees->get($eid);
            if (!$emp) continue;
            $key = $emp->normalized_name ?: $this->identityResolver->normalizePersonName($emp->full_name);
            if ($key === '') $key = 'sin_nombre_' . $eid;

            if (isset($byIdentity[$key])) {
                $duplicadosFusionados++;
            }
            $byIdentity[$key] ??= ['employee_ids' => [], 'normal' => false, 'fiscal' => false, 'nombre_original' => $emp->full_name];
            $byIdentity[$key]['employee_ids'][] = $eid;
            $byIdentity[$key]['normal'] = $byIdentity[$key]['normal'] || $flags['normal'];
            $byIdentity[$key]['fiscal'] = $byIdentity[$key]['fiscal'] || $flags['fiscal'];
        }

        // ── 3. Periodo anterior — roster ya construido (para altas/bajas), por identidad ──
        $prevPeriod = $period->previousMonthly($allPeriods);
        $prevRosterByIdentity = collect();
        if ($prevPeriod) {
            $prevRosterByIdentity = DB::table('period_employee_rosters')
                ->where('period_id', $prevPeriod->id)
                ->where('is_active_for_period', true)
                ->get()
                ->keyBy(fn ($r) => $r->nombre_normalizado);
        }

        // ── 4. Resolver sucursal: cualquier employee_id del grupo, periodo actual → histórico ──
        $currentAssignments = DB::table('employee_branch_assignments')
            ->where('period_id', $period->id)
            ->whereIn('employee_id', $rawEmployeeIds)
            ->whereNotNull('branch_id')
            ->pluck('branch_id', 'employee_id');

        $historicalMap = [];
        $needFallback = array_values(array_diff($rawEmployeeIds, $currentAssignments->keys()->all()));
        if (!empty($needFallback)) {
            $historyRows = DB::table('employee_branch_assignments')
                ->whereIn('employee_id', $needFallback)
                ->whereNotNull('branch_id')
                ->orderByDesc('period_id')
                ->get(['employee_id', 'branch_id']);
            foreach ($historyRows as $hr) {
                $eid = (int) $hr->employee_id;
                if (!isset($historicalMap[$eid])) {
                    $historicalMap[$eid] = (int) $hr->branch_id;
                }
            }
        }

        $allBranchIds = array_values(array_unique(array_filter(array_merge(
            $currentAssignments->values()->all(),
            array_values($historicalMap)
        ))));
        $branches = empty($allBranchIds) ? collect() : Branch::query()->whereIn('id', $allBranchIds)->get()->keyBy('id');

        // ── 5. Construir filas activas (una por identidad única) ──
        $now = now();
        $activeRows  = [];
        $sinSucursal = 0;
        $altas       = 0;

        foreach ($byIdentity as $normalizedName => $data) {
            // Cualquier employee_id del grupo puede traer la asignación de sucursal.
            $branchId = null;
            foreach ($data['employee_ids'] as $eid) {
                if (isset($currentAssignments[$eid])) { $branchId = (int) $currentAssignments[$eid]; break; }
            }
            if ($branchId === null) {
                foreach ($data['employee_ids'] as $eid) {
                    if (isset($historicalMap[$eid])) { $branchId = $historicalMap[$eid]; break; }
                }
            }

            $branch      = $branchId ? $branches->get($branchId) : null;
            $branchName  = $branch?->name;
            $isOperativa = $branchName ? $this->branchResolver->isSheetBranch($branchName) : false;
            if (!$branchId) $sinSucursal++;

            $source = ($data['normal'] && $data['fiscal']) ? 'ambos' : ($data['fiscal'] ? 'noi_nomina_fiscal' : 'noi_nomina');
            $wasActivePrev = $prevRosterByIdentity->has($normalizedName);
            $movement = $wasActivePrev ? 'activo' : 'alta';
            if (!$wasActivePrev) $altas++;

            $primaryEmployeeId = $data['employee_ids'][0];

            $activeRows[] = [
                'period_id'                => $period->id,
                'employee_id'              => $primaryEmployeeId,
                'employee_key'             => $normalizedName,
                'nombre_normalizado'       => $normalizedName,
                'nombre_original'          => $data['nombre_original'],
                'branch_id'                => $branchId,
                'branch_name'              => $branchName,
                'is_branch_operativa'      => $isOperativa,
                'source'                   => $source,
                'appears_in_nomina_normal' => $data['normal'],
                'appears_in_nomina_fiscal' => $data['fiscal'],
                'is_active_for_period'     => true,
                'movement_type'            => $movement,
                'created_at'               => $now,
                'updated_at'               => $now,
            ];
        }

        // ── 6. Bajas: activos el periodo anterior, ausentes este periodo ──
        $bajaRows = [];
        foreach ($prevRosterByIdentity as $normalizedName => $prevRow) {
            if (isset($byIdentity[$normalizedName])) continue; // sigue activo

            $bajaRows[] = [
                'period_id'                => $period->id,
                'employee_id'              => $prevRow->employee_id,
                'employee_key'             => $normalizedName,
                'nombre_normalizado'       => $normalizedName,
                'nombre_original'          => $prevRow->nombre_original,
                'branch_id'                => $prevRow->branch_id,
                'branch_name'              => $prevRow->branch_name,
                'is_branch_operativa'      => (bool) $prevRow->is_branch_operativa,
                'source'                   => $prevRow->source,
                'appears_in_nomina_normal' => false,
                'appears_in_nomina_fiscal' => false,
                'is_active_for_period'     => false,
                'movement_type'            => 'baja',
                'created_at'               => $now,
                'updated_at'               => $now,
            ];
        }

        // ── 7. Persistir (idempotente: borra+inserta) ──
        DB::table('period_employee_rosters')->where('period_id', $period->id)->delete();
        $allRows = array_merge($activeRows, $bajaRows);
        foreach (array_chunk($allRows, 500) as $chunk) {
            DB::table('period_employee_rosters')->insert($chunk);
        }

        return [
            'active_count'          => count($activeRows),
            'altas'                 => $altas,
            'bajas'                 => count($bajaRows),
            'sin_sucursal'          => $sinSucursal,
            'prev_period_id'        => $prevPeriod?->id,
            'duplicados_fusionados' => $duplicadosFusionados,
        ];
    }
}
