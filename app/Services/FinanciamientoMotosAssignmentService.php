<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Period;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Resolves and persists employee_id/branch_id on fact_expenses rows for
 * "PAGO FINANCIAMIENTO MOTO" / "COMPRA DE CASCOS" (gastos_lendus_excel, branch_id
 * always NULL at import time because that file has no sucursal column).
 *
 * Every row must end up tied to a real employee and one of the operative branches —
 * these are real people's expenses, never a generic "sin asignar"/"no operativo" bucket.
 * Resolution never invents a branch: it only accepts a branch that traces back to a
 * confirmed employee→branch assignment (current period, historical, canonical
 * duplicate/alias, or Lendus employee directory).
 */
class FinanciamientoMotosAssignmentService
{
    public const CONCEPTS = ['PAGO FINANCIAMIENTO MOTO', 'COMPRA DE CASCOS'];

    public function __construct(
        private readonly PersonIdentityResolverService $personResolver,
        private readonly BranchRadiographyCalculator $branchCalculator,
    ) {
    }

    /**
     * Resolve every Financiamiento de Motos/Cascos row of a period, persisting
     * employee_id/branch_id on fact_expenses. Idempotent — rows already tied to an
     * employee+branch are reported as-is and never re-touched or duplicated.
     *
     * @return array<int, array{
     *   fact_expense_id:int, concept:string, amount:float, period_id:int, report_upload_id:int,
     *   nombre_original:string, empleado_encontrado:?string, employee_id:?int,
     *   sucursal:?string, branch_id:?int, metodo:string, confianza:float, estado:string,
     * }>
     */
    public function assignForPeriod(Period $period, array $dataIds, bool $dryRun = false): array
    {
        $operativeMap = $this->branchCalculator->buildBranchMap()['operative'];

        $lendusExcelId = DB::table('data_sources')->where('code', 'gastos_lendus_excel')->value('id');
        if (!$lendusExcelId) {
            return [];
        }

        $rows = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('ru.data_source_id', $lendusExcelId)
            ->where('e.category', 'Nómina y Capital Humano')
            ->whereIn(DB::raw("UPPER(TRIM(COALESCE(e.concept,'')))"), self::CONCEPTS)
            ->select(
                'e.id', 'e.period_id', 'e.report_upload_id', 'e.employee_id', 'e.branch_id',
                'e.concept', 'e.observations',
                DB::raw("COALESCE(NULLIF(e.paid_amount,0), e.amount) as amount")
            )
            ->get();

        $results = [];

        foreach ($rows as $row) {
            $concept = trim((string) $row->concept);
            $amount  = (float) $row->amount;

            if ($row->employee_id && $row->branch_id && isset($operativeMap[$row->branch_id])) {
                $employee = Employee::find($row->employee_id);
                $results[] = [
                    'fact_expense_id'     => $row->id,
                    'concept'             => $concept,
                    'amount'              => $amount,
                    'period_id'           => $row->period_id,
                    'report_upload_id'    => $row->report_upload_id,
                    'nombre_original'     => trim((string) $row->observations),
                    'empleado_encontrado' => $employee?->full_name,
                    'employee_id'         => $row->employee_id,
                    'sucursal'            => $operativeMap[$row->branch_id],
                    'branch_id'           => $row->branch_id,
                    'metodo'              => 'ya_asignado',
                    'confianza'           => 1.0,
                    'estado'              => 'ya_asignado',
                ];
                continue;
            }

            $resolution = $this->resolve((string) $row->observations, $dataIds, $operativeMap);

            if ($resolution !== null) {
                if (!$dryRun) {
                    DB::table('fact_expenses')->where('id', $row->id)->update([
                        'employee_id' => $resolution['employee_id'],
                        'branch_id'   => $resolution['branch_id'],
                        'updated_at'  => now(),
                    ]);
                }
                $results[] = [
                    'fact_expense_id'     => $row->id,
                    'concept'             => $concept,
                    'amount'              => $amount,
                    'period_id'           => $row->period_id,
                    'report_upload_id'    => $row->report_upload_id,
                    'nombre_original'     => trim((string) $row->observations),
                    'empleado_encontrado' => $resolution['employee_name'],
                    'employee_id'         => $resolution['employee_id'],
                    'sucursal'            => $resolution['branch_name'],
                    'branch_id'           => $resolution['branch_id'],
                    'metodo'              => $resolution['method'],
                    'confianza'           => $resolution['confidence'],
                    'estado'              => 'resuelto',
                ];
            } else {
                $results[] = [
                    'fact_expense_id'     => $row->id,
                    'concept'             => $concept,
                    'amount'              => $amount,
                    'period_id'           => $row->period_id,
                    'report_upload_id'    => $row->report_upload_id,
                    'nombre_original'     => trim((string) $row->observations),
                    'empleado_encontrado' => null,
                    'employee_id'         => null,
                    'sucursal'            => null,
                    'branch_id'           => null,
                    'metodo'              => 'sin_resolver',
                    'confianza'           => 0.0,
                    'estado'              => 'sin_resolver',
                ];
            }
        }

        return $results;
    }

    /**
     * Same as assignForPeriod() but throws with an actionable message (record id,
     * original text, amount, upload) if ANY row remains unresolved — used by the
     * generation pipeline to stop before building the report instead of silently
     * bucketing the amount as "sin asignar"/"no operativo".
     */
    public function assignForPeriodOrFail(Period $period, array $dataIds): array
    {
        $results    = $this->assignForPeriod($period, $dataIds);
        $unresolved = array_filter($results, fn (array $r) => $r['estado'] === 'sin_resolver');

        if (!empty($unresolved)) {
            $detail = collect($unresolved)->map(fn (array $r) => sprintf(
                '  - fact_expenses.id=%d | %s | "%s" | $%s | report_upload_id=%d',
                $r['fact_expense_id'], $r['concept'], $r['nombre_original'], number_format($r['amount'], 2), $r['report_upload_id']
            ))->implode("\n");

            throw new \RuntimeException(
                "No se pudo determinar empleado/sucursal para " . count($unresolved) .
                " registro(s) de Financiamiento de Motos/Cascos del periodo {$period->label} — " .
                "generación detenida (no se oculta ni se inventa sucursal). Corrige el nombre o crea/asigna " .
                "la sucursal del colaborador en el panel de Asignación de Sucursal y vuelve a generar:\n" . $detail
            );
        }

        return $results;
    }

    /**
     * Resolve employee + operative branch for a raw `observations` string.
     * Returns null only if no source (exact/reordered name, alias, historical
     * assignment, canonical duplicate, Lendus directory) yields a known operative branch.
     */
    public function resolve(string $rawObservations, array $dataIds, ?array $operativeMap = null): ?array
    {
        $operativeMap ??= $this->branchCalculator->buildBranchMap()['operative'];
        $candidates = $this->extractNameCandidates($rawObservations);
        if (empty($candidates)) {
            return null;
        }

        // Pass 1: prefer an EXACT (or same-words-reordered) match across every candidate
        // segment — never let a merely-fuzzy match on one segment pre-empt an exact match
        // available on another segment of the same observations string.
        $bestFuzzy = null;
        foreach ($candidates as $name) {
            $match = $this->personResolver->findExistingPersonMatch($name);
            if (!$match) {
                continue;
            }
            if (in_array($match['method'], ['exact_name', 'exact_name_reordered'], true)) {
                $resolved = $this->resolveBranchForEmployee($match['employee'], $dataIds, $operativeMap);
                if ($resolved) {
                    return $resolved + ['method' => $match['method'], 'confidence' => 1.0];
                }
            } elseif ($bestFuzzy === null || $match['score'] > $bestFuzzy['match']['score']) {
                $bestFuzzy = ['name' => $name, 'match' => $match];
            }
        }

        // Pass 2: best fuzzy (≥80%) match across all segments, only if no exact match resolved.
        if ($bestFuzzy) {
            $resolved = $this->resolveBranchForEmployee($bestFuzzy['match']['employee'], $dataIds, $operativeMap);
            if ($resolved) {
                return $resolved + ['method' => 'fuzzy_name', 'confidence' => round($bestFuzzy['match']['score'] / 100, 2)];
            }
        }

        // Pass 3: Lendus employee directory (exact, then fuzzy ≥85%), any segment.
        foreach ($candidates as $name) {
            $resolved = $this->resolveViaLendusDirectory($name, $operativeMap);
            if ($resolved) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Split "NOMBRE | nota libre" (either order) into every non-empty segment, stripping
     * known concept-phrase prefixes from each — the source Excel is inconsistent about
     * which side of the '|' carries the full name.
     */
    private function extractNameCandidates(string $rawObservations): array
    {
        $segments = array_map('trim', explode('|', $rawObservations));
        $cleaned  = [];
        foreach ($segments as $seg) {
            $seg = trim((string) preg_replace(
                '/^(PAGO\s+)?(DE\s+)?FINANCIAMIENTO\s+(DE\s+)?MOTO\s*|^COMPRA\s+DE\s+CASCOS\s*/i',
                '',
                $seg
            ));
            if ($seg !== '') {
                $cleaned[] = $seg;
            }
        }

        return array_values(array_unique($cleaned));
    }

    private function resolveBranchForEmployee(Employee $employee, array $dataIds, array $operativeMap): ?array
    {
        // 1. This employee's own assignment for the current period (confirmed or automatic).
        $branchId = DB::table('employee_branch_assignments')
            ->where('employee_id', $employee->id)
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('branch_id')
            ->whereIn('branch_id', array_keys($operativeMap))
            ->orderByDesc('period_id')
            ->value('branch_id');

        // 2. Most recent historical assignment (any other period).
        $branchId = $branchId ?: $this->personResolver->resolveBranchFromExistingAssignments($employee->id);

        // 3. Canonical duplicate employee record / confirmed alias.
        $branchId = $branchId
            ?: $this->personResolver->resolveBranchFromCanonicalEmployee($employee)
            ?: $this->personResolver->resolveBranchFromAlias($employee);

        if ($branchId && isset($operativeMap[(int) $branchId])) {
            return [
                'employee_id'   => $employee->id,
                'employee_name' => $employee->full_name,
                'branch_id'     => (int) $branchId,
                'branch_name'   => $operativeMap[(int) $branchId],
            ];
        }

        return null;
    }

    private function resolveViaLendusDirectory(string $name, array $operativeMap): ?array
    {
        $normalized = $this->personResolver->normalizePersonName($name);
        if ($normalized === '') {
            return null;
        }

        $best      = null;
        $bestScore = 0.0;
        foreach (DB::table('lendus_employee_directory')
            ->where('is_operational', true)
            ->whereNotNull('normalized_name')
            ->get(['codigo', 'nombre', 'normalized_name', 'inferred_branch_id']) as $rec
        ) {
            if ($rec->normalized_name === $normalized) {
                $best      = $rec;
                $bestScore = 100.0;
                break;
            }
            $score = $this->personResolver->scoreNameSimilarity($normalized, (string) $rec->normalized_name);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $rec;
            }
        }

        if (!$best || $bestScore < 85.0) {
            return null;
        }

        $branchId = $best->inferred_branch_id ? (int) $best->inferred_branch_id : null;
        if (!$branchId && $best->codigo) {
            $branchResolver = app(BranchResolverService::class);
            $branchName     = $branchResolver->resolveBranchNameFromCode((string) $best->codigo);
            if ($branchName) {
                $branchId = DB::table('branches')
                    ->whereRaw('UPPER(TRIM(name)) = ?', [strtoupper(trim($branchName))])
                    ->value('id');
                $branchId = $branchId ? (int) $branchId : null;
            }
        }

        if ($branchId && isset($operativeMap[$branchId])) {
            return [
                'employee_id'   => null,
                'employee_name' => $best->nombre,
                'branch_id'     => $branchId,
                'branch_name'   => $operativeMap[$branchId],
                'method'        => $bestScore >= 100.0 ? 'lendus_directory_exact' : 'lendus_directory_fuzzy',
                'confidence'    => round($bestScore / 100, 2),
            ];
        }

        return null;
    }
}
