<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Period;
use App\Services\PersonIdentityResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditEmployeeBranchesCommand extends Command
{
    protected $signature = 'reportes:audit-employee-branches
                                {period_id}
                                {--detail : Muestra el detalle fila por fila con candidatos}
                                {--export : Exporta CSV a storage/app/auditorias/}';

    protected $description = 'Auditoría de asignaciones empleado→sucursal: estado, método, candidatos y motivo por persona. No modifica datos.';

    public function handle(PersonIdentityResolverService $resolver): int
    {
        $periodId = (int) $this->argument('period_id');
        $period   = Period::find($periodId);

        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(empty($weeklyIds) ? [] : $weeklyIds, [$period->id])));

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA EMPLEADO→SUCURSAL — {$period->label} (ID {$periodId})");
        $this->info('════════════════════════════════════════════════════════════');

        // Scope strictly to employees detected in NOI/NOI Fiscal for this period — the
        // same population EmployeeBranchAutoMatchService and the "personas sin sucursal"
        // incidence use. employee_branch_assignments can also hold rows written by other
        // scans (e.g. the Cobranza employee-catalog scan) for people outside this NOI set;
        // those are out of scope here and would otherwise show up as noisy false positives.
        $noiEmployeeIds = DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('employee_id')
            ->distinct()
            ->pluck('employee_id');

        $assignments = DB::table('employee_branch_assignments as eba')
            ->leftJoin('employees as emp', 'eba.employee_id', '=', 'emp.id')
            ->leftJoin('branches as b', 'eba.branch_id', '=', 'b.id')
            ->where('eba.period_id', $periodId)
            ->whereIn('eba.employee_id', $noiEmployeeIds)
            ->select(
                'eba.employee_id', 'eba.branch_id', 'eba.match_type', 'eba.confidence',
                'eba.was_manual_reviewed', 'eba.notes', 'eba.source_reference',
                'emp.full_name', 'emp.normalized_name', 'emp.employee_code', 'b.name as branch_name',
            )
            ->get();

        if ($assignments->isEmpty()) {
            $this->warn('  No hay asignaciones para las personas de NOI en este periodo. Ejecuta primero el automatch:');
            $this->line("  php artisan reportes:automatch-employee-branches {$periodId} --apply");
            return self::SUCCESS;
        }

        $movStats = DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('employee_id')
            ->selectRaw('employee_id, COUNT(*) as cnt, SUM(amount) as monto')
            ->groupBy('employee_id')
            ->get()
            ->keyBy('employee_id');

        // Only rows resolved via the last-resort fuzzy path (or left unmatched) warrant a
        // second opinion — historical/exact/majority are deterministic, non-ambiguous
        // resolutions and don't need candidate diagnosis or manual review.
        $needsDiagnosis = $assignments->filter(fn ($a) => !$a->was_manual_reviewed
            && in_array($a->match_type, ['unmatched', 'fuzzy'], true));

        $diagnoses = [];
        if ($needsDiagnosis->isNotEmpty()) {
            $allEmployees = Employee::query()->whereNotNull('normalized_name')->get(['id', 'full_name', 'normalized_name']);
            $allEmployeeIds = $allEmployees->pluck('id')->all();

            $candidateBranchesGlobal = empty($allEmployeeIds) ? [] : DB::table('employee_branch_assignments as eba')
                ->join('branches as b', 'eba.branch_id', '=', 'b.id')
                ->whereIn('eba.employee_id', $allEmployeeIds)
                ->whereNotNull('eba.branch_id')
                ->orderByDesc('eba.period_id')
                ->select('eba.employee_id', 'b.name as branch_name')
                ->get()->unique('employee_id')->pluck('branch_name', 'employee_id')->all();

            $placementsPreloaded = empty($dataIds) ? collect() : DB::table('fact_placements')
                ->whereIn('period_id', $dataIds)->whereNotNull('branch_id')
                ->selectRaw('normalized_promoter_name, MAX(promoter_code) as promoter_code, MAX(branch_id) as branch_id')
                ->groupBy('normalized_promoter_name')->get()->keyBy('normalized_promoter_name');

            $lendusPreloaded = DB::table('lendus_employee_directory')
                ->where('is_operational', true)->whereNotNull('normalized_name')
                ->get(['id', 'codigo', 'nombre', 'normalized_name', 'puesto', 'estatus', 'inferred_branch_id', 'inferred_branch_name']);

            $contractsPreloaded = $resolver->buildContractsIndex($dataIds);

            $rawMovements = DB::table('fact_noi_movements')
                ->whereIn('period_id', $dataIds)
                ->whereIn('employee_id', $needsDiagnosis->pluck('employee_id')->all())
                ->select('employee_id', 'movement_date', 'concept', 'amount', 'raw_payload')
                ->get()->groupBy('employee_id');

            foreach ($needsDiagnosis as $a) {
                $trace = $resolver->buildResolutionTrace(
                    employeeId: (int) $a->employee_id,
                    fullName: (string) $a->full_name,
                    allEmployees: $allEmployees,
                    sampleMovs: $rawMovements[$a->employee_id] ?? collect(),
                    employeeCode: $a->employee_code,
                    historicalBranch: null,
                    dataIds: $dataIds,
                    candidateBranchesPreloaded: $candidateBranchesGlobal,
                    placementsPreloaded: $placementsPreloaded,
                    lendusPreloaded: $lendusPreloaded,
                    contractsPreloaded: $contractsPreloaded,
                );
                $diagnoses[$a->employee_id] = $trace['candidate_matches'];
            }
        }

        $rows = [];
        foreach ($assignments as $a) {
            $estado = match (true) {
                (bool) $a->was_manual_reviewed => 'manual',
                $a->match_type === 'unmatched' => 'sin_asignar',
                $a->match_type === 'fuzzy' => 'revisar',
                default => 'asignado',
            };

            $cands = $diagnoses[$a->employee_id] ?? [];
            $c1 = $cands[0] ?? null;
            $c2 = $cands[1] ?? null;

            $rows[] = [
                'empleado'              => $a->full_name ?? "Emp #{$a->employee_id}",
                'identificador'         => $a->employee_code ?? '',
                'nombre_normalizado'    => $a->normalized_name ?? '',
                'registros'             => (int) ($movStats[$a->employee_id]->cnt ?? 0),
                'monto'                 => (float) ($movStats[$a->employee_id]->monto ?? 0),
                'candidato_1'           => $c1['name'] ?? '',
                'sucursal_candidato_1'  => $c1['branch'] ?? '',
                'confianza_1'           => $c1['score'] ?? '',
                'candidato_2'           => $c2['name'] ?? '',
                'sucursal_candidato_2'  => $c2['branch'] ?? '',
                'confianza_2'           => $c2['score'] ?? '',
                'sucursal_asignada'     => $a->branch_name ?? '',
                'metodo'                => $a->match_type ?? '',
                'estado'                => $estado,
                'motivo'                => $a->notes ?? '',
            ];
        }

        $summary = collect($rows)->countBy('estado');
        $this->line('');
        $this->info('════ RESUMEN ════');
        $this->line(str_pad('Total asignaciones', 30) . count($rows));
        foreach (['asignado', 'manual', 'revisar', 'sin_asignar'] as $key) {
            $this->line(str_pad(ucfirst(str_replace('_', ' ', $key)), 30) . ($summary[$key] ?? 0));
        }

        if ($this->option('detail')) {
            $this->line('');
            $this->info('════ DETALLE (revisar / sin_asignar) ════');
            $this->line(sprintf(
                '  %-25s %-14s %-6s %10s  %-20s %-6s  %-20s %-6s  %-10s %-10s',
                'EMPLEADO', 'ID', 'REGS', 'MONTO', 'CANDIDATO 1', 'CONF1', 'CANDIDATO 2', 'CONF2', 'MÉTODO', 'ESTADO',
            ));
            $this->line('  ' . str_repeat('─', 150));
            foreach ($rows as $r) {
                if (!in_array($r['estado'], ['revisar', 'sin_asignar'], true)) {
                    continue;
                }
                $this->line(sprintf(
                    '  %-25s %-14s %-6d %10s  %-20s %-6s  %-20s %-6s  %-10s %-10s',
                    mb_substr($r['empleado'], 0, 25),
                    mb_substr((string) $r['identificador'], 0, 14),
                    $r['registros'],
                    number_format($r['monto'], 2),
                    mb_substr((string) $r['candidato_1'], 0, 20),
                    $r['confianza_1'] !== '' ? $r['confianza_1'] . '%' : '—',
                    mb_substr((string) $r['candidato_2'], 0, 20),
                    $r['confianza_2'] !== '' ? $r['confianza_2'] . '%' : '—',
                    $r['metodo'],
                    $r['estado'],
                ));
            }
        }

        if ($this->option('export')) {
            $this->exportCsv($periodId, $rows);
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function exportCsv(int $periodId, array $rows): void
    {
        $dir  = 'auditorias';
        $file = "empleado_sucursal_periodo_{$periodId}_" . now()->format('Ymd_His') . '.csv';
        $path = "{$dir}/{$file}";

        $headers = [
            'Empleado', 'Identificador', 'Nombre normalizado', 'Registros', 'Monto',
            'Candidato 1', 'Sucursal candidato 1', 'Confianza 1',
            'Candidato 2', 'Sucursal candidato 2', 'Confianza 2',
            'Sucursal asignada', 'Método', 'Estado', 'Motivo',
        ];
        $csv = static fn (string $v): string => '"' . str_replace('"', '""', $v) . '"';

        $lines = [implode(',', $headers)];
        foreach ($rows as $r) {
            $lines[] = implode(',', [
                $csv((string) $r['empleado']),
                $csv((string) $r['identificador']),
                $csv((string) $r['nombre_normalizado']),
                $r['registros'],
                number_format($r['monto'], 2, '.', ''),
                $csv((string) $r['candidato_1']),
                $csv((string) $r['sucursal_candidato_1']),
                $csv((string) $r['confianza_1']),
                $csv((string) $r['candidato_2']),
                $csv((string) $r['sucursal_candidato_2']),
                $csv((string) $r['confianza_2']),
                $csv((string) $r['sucursal_asignada']),
                $csv((string) $r['metodo']),
                $csv((string) $r['estado']),
                $csv((string) $r['motivo']),
            ]);
        }

        Storage::disk('local')->makeDirectory($dir);
        Storage::disk('local')->put($path, implode("\n", $lines));
        $this->info("  CSV exportado: storage/app/{$path}");
    }
}
