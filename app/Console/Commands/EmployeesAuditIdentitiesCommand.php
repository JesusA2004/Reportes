<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\BranchResolverService;
use App\Services\EmployeeNameCanonicalizer;
use App\Services\PersonIdentityResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría de calidad de identidad de colaboradores — solo lectura, nunca modifica
 * la base de datos. Ver EmployeesNormalizeCommand para fusionar duplicados de forma
 * segura después de revisar este reporte.
 */
class EmployeesAuditIdentitiesCommand extends Command
{
    protected $signature = 'employees:audit-identities {--json : Salida en JSON}';

    protected $description = 'Audita duplicados exactos/probables, colaboradores sin sucursal, rutas usadas como sucursal y NOI $0 sin respaldo Lendus.';

    public function handle(
        EmployeeNameCanonicalizer $canonicalizer,
        PersonIdentityResolverService $identityResolver,
        BranchResolverService $branchResolver,
    ): int {
        $employees = Employee::query()->get(['id', 'full_name', 'normalized_name', 'source_system']);

        // ── 1) Duplicados exactos: mismo normalized_name, distinto employee_id ──
        $exactGroups = $employees->groupBy('normalized_name')->filter(fn ($g) => $g->count() > 1);

        // ── 2) Duplicados probables (typo/acento/espacio): fuzzy sobre nombres únicos ──
        $uniqueNames = $employees->pluck('normalized_name')->filter()->unique()->values()->all();
        $canonicalMap = $canonicalizer->buildCanonicalMap($uniqueNames);
        $fuzzyGroups = [];
        foreach ($canonicalMap as $alias => $canonical) {
            if ($alias === $canonical) continue;
            $fuzzyGroups[$canonical][] = $alias;
        }

        // ── 3) Sin sucursal: nunca tuvieron un employee_branch_assignments con branch_id ──
        $withAssignment = DB::table('employee_branch_assignments')->whereNotNull('branch_id')->distinct()->pluck('employee_id')->all();
        $sinSucursal = $employees->whereNotIn('id', $withAssignment)->values();

        // ── 4) Ruta usada como sucursal: branch_id apunta a un Branch no operativo ──
        $rutaComoSucursal = DB::table('employee_branch_assignments as eba')
            ->join('branches as b', 'eba.branch_id', '=', 'b.id')
            ->whereNotNull('eba.branch_id')
            ->select('eba.employee_id', 'b.name as branch_name')
            ->distinct()
            ->get()
            ->filter(fn ($r) => !$branchResolver->isSheetBranch((string) $r->branch_name) && strtoupper(trim($r->branch_name)) !== 'CORPORATIVO')
            ->map(fn ($r) => ['employee_id' => (int) $r->employee_id, 'branch_name' => $r->branch_name])
            ->values();

        // ── 5) Aliases confirmados ──
        $aliasesCount = DB::table('employee_aliases')->count();

        // ── 6) NOI $0 obsoletos ya persistidos (contaminación previa a este fix) ──
        // Empleados cuyo ÚNICO rastro en fact_noi_movements es la fila sintética
        // headcount_only ($0), y que hoy no tienen ninguna evidencia fuera de NOI.
        $headcountOnlyEmployeeIds = DB::table('fact_noi_movements')
            ->select('employee_id')
            ->groupBy('employee_id')
            ->havingRaw("SUM(CASE WHEN concept_type = 'headcount_only' THEN 0 ELSE 1 END) = 0")
            ->pluck('employee_id');

        $staleZeroCandidates = [];
        if ($headcountOnlyEmployeeIds->isNotEmpty()) {
            $candidates = Employee::query()->whereIn('id', $headcountOnlyEmployeeIds)->get(['id', 'full_name']);
            foreach ($candidates as $c) {
                $evidence = $identityResolver->evaluateNoiCandidateEvidence($c->full_name);
                if (!$evidence['found']) {
                    $staleZeroCandidates[] = ['id' => $c->id, 'full_name' => $c->full_name];
                }
            }
        }

        $report = [
            'duplicados_exactos'          => $exactGroups->count(),
            'duplicados_exactos_detalle'  => $exactGroups->map(fn ($g) => ['normalized_name' => $g->first()->normalized_name, 'employee_ids' => $g->pluck('id')->all(), 'names' => $g->pluck('full_name')->unique()->values()->all()])->values()->all(),
            'duplicados_probables'        => count($fuzzyGroups),
            'duplicados_probables_detalle'=> collect($fuzzyGroups)->map(fn ($aliases, $canonical) => ['canonical' => $canonical, 'aliases' => $aliases])->values()->all(),
            'sin_sucursal'                => $sinSucursal->count(),
            'sin_sucursal_detalle'        => $sinSucursal->map(fn ($e) => ['id' => $e->id, 'full_name' => $e->full_name])->values()->all(),
            'ruta_usada_como_sucursal'    => $rutaComoSucursal->count(),
            'ruta_usada_como_sucursal_detalle' => $rutaComoSucursal->values()->all(),
            'aliases_detectados'          => $aliasesCount,
            'noi_cero_obsoletos_persistidos' => count($staleZeroCandidates),
            'noi_cero_obsoletos_persistidos_detalle' => $staleZeroCandidates,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $this->info('Auditoría de identidad de colaboradores');
        $this->line('');
        $this->line("Duplicados exactos (mismo nombre normalizado): {$report['duplicados_exactos']}");
        $this->line("Duplicados probables (typo/acento/espacio):    {$report['duplicados_probables']}");
        $this->line("Sin sucursal (nunca asignados):                {$report['sin_sucursal']}");
        $this->line("Ruta usada como sucursal:                      {$report['ruta_usada_como_sucursal']}");
        $this->line("Aliases confirmados:                           {$report['aliases_detectados']}");
        $this->line("NOI \$0 ya persistidos sin respaldo Lendus:     {$report['noi_cero_obsoletos_persistidos']}");
        $this->line('');

        if (!empty($report['duplicados_probables_detalle'])) {
            $this->line('── Duplicados probables ──');
            $rows = [];
            foreach ($report['duplicados_probables_detalle'] as $g) {
                $rows[] = [mb_strtoupper($g['canonical']), implode(' | ', array_map('mb_strtoupper', $g['aliases']))];
            }
            $this->table(['Canónico', 'Alias fusionables'], $rows);
        }

        if (!empty($report['ruta_usada_como_sucursal_detalle'])) {
            $this->line('── Ruta usada como sucursal (revisar employee_branch_assignments) ──');
            $rows = array_map(fn ($r) => [$r['employee_id'], $r['branch_name']], $report['ruta_usada_como_sucursal_detalle']);
            $this->table(['employee_id', 'branch_name (no operativa)'], $rows);
        }

        if (!empty($report['noi_cero_obsoletos_persistidos_detalle'])) {
            $this->line('── NOI $0 ya persistidos sin respaldo (candidatos a limpieza manual) ──');
            $rows = array_map(fn ($r) => [$r['id'], $r['full_name']], $report['noi_cero_obsoletos_persistidos_detalle']);
            $this->table(['id', 'full_name'], $rows);
        }

        return self::SUCCESS;
    }
}
