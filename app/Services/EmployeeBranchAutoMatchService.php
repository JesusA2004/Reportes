<?php

namespace App\Services;

use App\Enums\MatchType;
use App\Enums\SourceType;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\NoiMovement;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use App\Services\PersonIdentityResolverService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resolves branch assignments for employees detected in NOI / NOI Fiscal.
 *
 * Performance contract: this service must run in O(cobranza_rows + placements_rows +
 * portfolios_rows + expenses_rows + employees) per period — NEVER O(employees × cobranza_rows).
 * All candidate sources (cobranza, colocación, cartera, gastos, directorio Lendus,
 * histórico, alias, empleados canónicos) are preindexed ONCE before the employee loop.
 * The employee loop itself performs only in-memory hash lookups and, as a last resort,
 * fuzzy comparisons against a small reduced candidate pool (distinct normalized names
 * seen in cobranza/colocación/directorio — typically a few hundred, never the raw
 * row count).
 */
class EmployeeBranchAutoMatchService
{
    public function __construct(
        private PersonIdentityResolverService $resolver,
        private BranchResolverService $branchResolver,
    ) {}

    public function handle(?int $periodId = null, ?callable $progress = null): array
    {
        $periods = Period::query()
            ->when($periodId, fn ($query) => $query->whereKey($periodId))
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderByDesc('sequence')
            ->get();

        $totals = $this->emptyStats();
        $start  = microtime(true);

        // Scoped query counting via Laravel's query log (not DB::listen — a permanent
        // listener would leak memory across repeated calls in a long-lived worker process).
        $wasLogging = DB::logging();
        if (!$wasLogging) {
            DB::enableQueryLog();
        }
        $queryCountBefore = count(DB::getQueryLog());

        foreach ($periods as $period) {
            $result = $this->processPeriod($period, $progress);
            foreach ($result as $key => $value) {
                if (array_key_exists($key, $totals)) {
                    $totals[$key] += $value;
                }
            }
        }

        $totals['duration_ms'] = (int) round((microtime(true) - $start) * 1000);
        $totals['query_count'] = count(DB::getQueryLog()) - $queryCountBefore;
        if (!$wasLogging) {
            DB::disableQueryLog();
        }

        return $totals;
    }

    private function emptyStats(): array
    {
        return [
            'processed'        => 0,
            'unique_persons'   => 0,
            'matched'          => 0,
            'unmatched'        => 0,
            'ambiguous'        => 0,
            'manual_kept'      => 0,
            'exact_identifier' => 0,
            'historical'       => 0,
            'exact_name'       => 0,
            'majority'         => 0,
            'fuzzy'            => 0,
            'duration_ms'      => 0,
            'query_count'      => 0,
        ];
    }

    private function processPeriod(Period $period, ?callable $progress = null): array
    {
        $stats = $this->emptyStats();

        $coveredWeekIds    = $this->resolveCoveredWeekIds($period);
        $noiUploadIds      = $this->resolveUploadIdsForSource('noi_nomina', $coveredWeekIds);
        $fiscalUploadIds   = $this->resolveUploadIdsForSource('noi_nomina_fiscal', $coveredWeekIds);
        $cobranzaUploadIds = $this->resolveUploadIdsForSource('lendus_ingresos_cobranza', $coveredWeekIds);
        $allNoiUploadIds   = array_values(array_unique(array_merge($noiUploadIds, $fiscalUploadIds)));

        $employees = $this->resolveEmployeesForPeriod($period, $allNoiUploadIds);

        if ($employees->isEmpty()) {
            return $stats;
        }

        $employeeIds    = $employees->pluck('id')->all();
        $operativeNames = array_map('strtoupper', $this->branchResolver->operativeFinancialBranches());

        $progress && $progress(55, 'Preparando índices', 'Agrupando cobranza, colocación, cartera y directorio Lendus una sola vez para todo el periodo.');

        // ── Preindex every candidate source ONCE (no per-employee queries beyond this point) ──
        $existingAssignments = EmployeeBranchAssignment::query()
            ->where('period_id', $period->id)
            ->whereIn('employee_id', $employeeIds)
            ->get()
            ->keyBy('employee_id');

        $cobranzaIndex   = $this->buildCobranzaIndex($cobranzaUploadIds, $operativeNames);
        $placementsIndex = $this->buildPlacementsIndex($period->id);
        $portfoliosIndex = $this->buildPortfoliosIndex($period->id, $operativeNames);
        $expensesIndex   = $this->buildExpensesIndex($period->id, $employeeIds, $operativeNames);
        $lendusIndex     = $this->buildLendusIndex();
        $historicalIndex = $this->buildHistoricalIndex($period->id, $employeeIds, $operativeNames);
        $aliasIndex      = $this->buildAliasIndex($employees, $operativeNames);
        $canonicalIndex  = $this->buildCanonicalIndex($employees, $operativeNames);

        $progress && $progress(65, 'Coincidencias exactas', 'Resolviendo por historial confirmado y nombre exacto.');

        // ── Unify NOI + NOI Fiscal duplicates: resolve branch ONCE per real person ──
        $groups = $employees->groupBy(fn (Employee $e) => $e->normalized_name ?: ('id:' . $e->id));

        $upserts     = [];
        $groupsDone  = 0;
        $totalGroups = max(1, $groups->count());

        foreach ($groups as $normName => $groupEmployees) {
            $stats['processed'] += $groupEmployees->count();
            $stats['unique_persons']++;
            $groupsDone++;

            if ($progress && $groupsDone % 40 === 0) {
                $pct = 65 + (int) round(($groupsDone / $totalGroups) * 20); // 65..85
                $progress(min(85, $pct), 'Resolviendo asignaciones', "Procesadas {$groupsDone}/{$totalGroups} personas.");
            }

            // A) Preserve any existing manual/confirmed assignment in the group.
            $manual = $groupEmployees
                ->map(fn (Employee $e) => $existingAssignments->get($e->id))
                ->filter(fn ($a) => $a && $a->was_manual_reviewed && $a->match_type?->value === MatchType::Manual->value && $a->branch_id)
                ->first();

            if ($manual) {
                foreach ($groupEmployees as $e) {
                    $existing = $existingAssignments->get($e->id);
                    if ($existing && $existing->was_manual_reviewed && $existing->match_type?->value === MatchType::Manual->value && $existing->branch_id) {
                        $stats['manual_kept']++;
                        continue; // untouched — never overwrite a manual assignment
                    }
                    $upserts[] = $this->buildUpsertRow(
                        $period->id, $e->id, $manual->branch_id, SourceType::Manual,
                        "heredado de asignación manual del empleado #{$manual->employee_id}",
                        MatchType::Manual, 1.00,
                        'Sucursal heredada de una asignación manual confirmada para la misma persona.',
                    );
                    $stats['matched']++;
                }
                continue;
            }

            $candidate = $this->resolveCandidateForGroup(
                $normName,
                $groupEmployees,
                $cobranzaIndex,
                $placementsIndex,
                $portfoliosIndex,
                $expensesIndex,
                $lendusIndex,
                $historicalIndex,
                $aliasIndex,
                $canonicalIndex,
            );

            $isAmbiguous = is_array($candidate) && $candidate['branch_id'] === null;
            $isMatched   = is_array($candidate) && $candidate['branch_id'] !== null;

            foreach ($groupEmployees as $e) {
                if ($isMatched) {
                    $upserts[] = $this->buildUpsertRow(
                        $period->id, $e->id, $candidate['branch_id'], $candidate['source_type'],
                        $candidate['source_reference'], $candidate['match_type'], $candidate['confidence'],
                        $candidate['notes'],
                    );
                } else {
                    $notes = $isAmbiguous
                        ? $candidate['notes']
                        : 'No se encontró sucursal clara para el colaborador en cobranza u operación. Requiere revisión manual.';
                    $upserts[] = $this->buildUpsertRow(
                        $period->id, $e->id, null, SourceType::Lendus, null, MatchType::Unmatched, 0, $notes,
                    );
                }
            }

            if ($isMatched) {
                $stats['matched']++;
                $stats[$candidate['stat_key']] = ($stats[$candidate['stat_key']] ?? 0) + 1;
            } elseif ($isAmbiguous) {
                $stats['ambiguous']++;
            } else {
                $stats['unmatched']++;
            }
        }

        $progress && $progress(95, 'Guardando asignaciones', 'Escribiendo asignaciones automáticas en lote.');

        foreach (array_chunk($upserts, 200) as $chunk) {
            EmployeeBranchAssignment::query()->upsert(
                $chunk,
                ['period_id', 'employee_id'],
                ['branch_id', 'source_type', 'source_reference', 'match_type', 'confidence', 'was_manual_reviewed', 'notes', 'updated_at'],
            );
        }

        $progress && $progress(100, 'Automatch completado', 'Asignaciones guardadas.');

        return $stats;
    }

    private function buildUpsertRow(
        int $periodId,
        int $employeeId,
        ?int $branchId,
        SourceType $sourceType,
        ?string $sourceReference,
        MatchType $matchType,
        float $confidence,
        string $notes,
    ): array {
        return [
            'period_id'           => $periodId,
            'employee_id'         => $employeeId,
            'branch_id'           => $branchId,
            'source_type'         => $sourceType->value,
            'source_reference'    => $sourceReference,
            'match_type'          => $matchType->value,
            'confidence'          => $confidence,
            'was_manual_reviewed' => false,
            'notes'               => $notes,
            'created_at'          => now(),
            'updated_at'          => now(),
        ];
    }

    /**
     * @return array{branch_id:int,source_type:SourceType,source_reference:string,match_type:MatchType,confidence:float,notes:string,stat_key:string}|null
     */
    private function resolveCandidateForGroup(
        string $normName,
        Collection $groupEmployees,
        array $cobranzaIndex,
        array $placementsIndex,
        array $portfoliosIndex,
        array $expensesIndex,
        array $lendusIndex,
        array $historicalIndex,
        array $aliasIndex,
        array $canonicalIndex,
    ): ?array {
        // D) Historical confirmed branch from a prior period — unambiguous by construction
        // (we only ever store the single most recent operative branch per employee).
        foreach ($groupEmployees as $e) {
            if (isset($historicalIndex[$e->id])) {
                return [
                    'branch_id'        => $historicalIndex[$e->id],
                    'source_type'      => SourceType::Lendus,
                    'source_reference' => 'employee_branch_assignments (histórico)',
                    'match_type'       => MatchType::Historical,
                    'confidence'       => 0.85,
                    'notes'            => 'Sucursal heredada de la última asignación confirmada en un periodo anterior.',
                    'stat_key'         => 'historical',
                ];
            }
        }

        $ambiguous = false;

        // E/F) Cobranza — exact name, then clear majority
        if (isset($cobranzaIndex['byName'][$normName])) {
            $branches = $cobranzaIndex['byName'][$normName];
            arsort($branches);
            $branchIds   = array_keys($branches);
            $topBranch   = $branchIds[0];
            $topCount    = $branches[$topBranch];
            $secondCount = isset($branchIds[1]) ? $branches[$branchIds[1]] : 0;

            if (count($branches) === 1) {
                return [
                    'branch_id' => $topBranch, 'source_type' => SourceType::Lendus,
                    'source_reference' => 'fact_recoveries', 'match_type' => MatchType::Exact,
                    'confidence' => 1.00, 'stat_key' => 'exact_name',
                    'notes' => "Coincidencia exacta por nombre en cobranza ({$topCount} registro(s), sucursal única).",
                ];
            }

            if ($topCount > $secondCount) {
                return [
                    'branch_id' => $topBranch, 'source_type' => SourceType::Lendus,
                    'source_reference' => 'fact_recoveries', 'match_type' => MatchType::Majority,
                    'confidence' => 0.85, 'stat_key' => 'majority',
                    'notes' => "Sucursal dominante en cobranza: {$topCount} registro(s) contra {$secondCount} del segundo lugar.",
                ];
            }

            $ambiguous = true; // tie between top branches — don't guess, try other sources
        }

        // G) Colocación (placements) — exact name, clear majority
        if (isset($placementsIndex['byName'][$normName])) {
            $list   = $placementsIndex['byName'][$normName];
            $top    = $list[0];
            $second = $list[1] ?? null;
            if (!$second || $top['cnt'] > $second['cnt']) {
                return [
                    'branch_id' => $top['branch_id'], 'source_type' => SourceType::Lendus,
                    'source_reference' => 'fact_placements', 'match_type' => MatchType::Majority,
                    'confidence' => 0.82, 'stat_key' => 'majority',
                    'notes' => 'Sucursal asignada con base en colocación del periodo.',
                ];
            }
            $ambiguous = true;
        }

        // Cartera (portfolios)
        if (isset($portfoliosIndex['byName'][$normName])) {
            $list   = $portfoliosIndex['byName'][$normName];
            $top    = $list[0];
            $second = $list[1] ?? null;
            if (!$second || $top['cnt'] > $second['cnt']) {
                return [
                    'branch_id' => $top['branch_id'], 'source_type' => SourceType::Lendus,
                    'source_reference' => 'fact_portfolios', 'match_type' => MatchType::Majority,
                    'confidence' => 0.82, 'stat_key' => 'majority',
                    'notes' => 'Sucursal asignada con base en cartera del periodo.',
                ];
            }
            $ambiguous = true;
        }

        // Gastos (expenses) — keyed by employee_id, not name
        foreach ($groupEmployees as $e) {
            if (isset($expensesIndex['byEmployee'][$e->id])) {
                $list   = $expensesIndex['byEmployee'][$e->id];
                $top    = $list[0];
                $second = $list[1] ?? null;
                if (!$second || $top['cnt'] > $second['cnt']) {
                    return [
                        'branch_id' => $top['branch_id'], 'source_type' => SourceType::Lendus,
                        'source_reference' => 'fact_expenses', 'match_type' => MatchType::Majority,
                        'confidence' => 0.80, 'stat_key' => 'majority',
                        'notes' => 'Sucursal asignada con base en gastos del periodo.',
                    ];
                }
                $ambiguous = true;
            }
        }

        // Directorio Lendus — exact name
        if (isset($lendusIndex['byName'][$normName])) {
            $record = $lendusIndex['byName'][$normName];
            if ($record->inferred_branch_id) {
                return [
                    'branch_id' => (int) $record->inferred_branch_id, 'source_type' => SourceType::Lendus,
                    'source_reference' => 'lendus_employee_directory', 'match_type' => MatchType::Exact,
                    'confidence' => 0.88, 'stat_key' => 'exact_name',
                    'notes' => "Sucursal del directorio Lendus. Código: {$record->codigo}, Puesto: {$record->puesto}.",
                ];
            }
            if ($record->codigo) {
                $branchName = $this->branchResolver->resolveBranchNameFromCode((string) $record->codigo);
                if ($branchName) {
                    $branchId = DB::table('branches')->whereRaw('UPPER(TRIM(name)) = ?', [strtoupper(trim($branchName))])->value('id');
                    if ($branchId) {
                        return [
                            'branch_id' => (int) $branchId, 'source_type' => SourceType::Lendus,
                            'source_reference' => 'lendus_employee_directory', 'match_type' => MatchType::Exact,
                            'confidence' => 0.85, 'stat_key' => 'exact_name',
                            'notes' => "Sucursal por código {$record->codigo} → {$branchName}. Puesto: {$record->puesto}.",
                        ];
                    }
                }
            }
        }

        // Alias confirmado / empleado canónico con mismo nombre normalizado
        if (isset($aliasIndex[$normName])) {
            return [
                'branch_id' => $aliasIndex[$normName], 'source_type' => SourceType::Lendus,
                'source_reference' => 'employee_aliases', 'match_type' => MatchType::Normalized,
                'confidence' => 0.95, 'stat_key' => 'exact_name',
                'notes' => 'Sucursal heredada de alias de persona confirmado.',
            ];
        }
        if (isset($canonicalIndex[$normName])) {
            return [
                'branch_id' => $canonicalIndex[$normName], 'source_type' => SourceType::Lendus,
                'source_reference' => 'employees.normalized_name', 'match_type' => MatchType::CanonicalSameName,
                'confidence' => 0.98, 'stat_key' => 'exact_name',
                'notes' => 'Sucursal heredada de otro registro con el mismo nombre normalizado.',
            ];
        }

        // G) Fuzzy — last resort, against a REDUCED candidate pool only (never raw rows).
        $pool = array_unique(array_merge(
            $cobranzaIndex['distinctNames'] ?? [],
            $placementsIndex['distinctNames'] ?? [],
            $lendusIndex['distinctNames'] ?? [],
        ));

        if (!empty($pool)) {
            // Cheap bucket by first token to shrink the comparison set further.
            $firstToken = strtok($normName, ' ') ?: $normName;
            $bucket = array_filter($pool, function ($candidateName) use ($firstToken, $normName) {
                $candidateFirstToken = strtok($candidateName, ' ') ?: $candidateName;
                return $candidateFirstToken === $firstToken
                    || str_starts_with($candidateName, $firstToken)
                    || str_starts_with($normName, $candidateFirstToken);
            });
            $searchPool = !empty($bucket) ? $bucket : $pool;

            $bestName = null;
            $bestScore = 0.0;
            $secondScore = 0.0;

            foreach ($searchPool as $candidateName) {
                $score = $this->resolver->scoreNameSimilarity($normName, $candidateName);
                if ($score > $bestScore) {
                    $secondScore = $bestScore;
                    $bestScore   = $score;
                    $bestName    = $candidateName;
                } elseif ($score > $secondScore) {
                    $secondScore = $score;
                }
            }

            if ($bestName && $bestScore >= 85.0 && ($bestScore - $secondScore) >= 8.0) {
                $branchInfo = $cobranzaIndex['byName'][$bestName] ?? null;
                if ($branchInfo) {
                    arsort($branchInfo);
                    $branchId = array_key_first($branchInfo);
                    $scoreLabel = round($bestScore, 1);

                    return [
                        'branch_id' => $branchId, 'source_type' => SourceType::Lendus,
                        'source_reference' => 'fact_recoveries (fuzzy)', 'match_type' => MatchType::Fuzzy,
                        'confidence' => round($bestScore / 100, 2), 'stat_key' => 'fuzzy',
                        'notes' => "Coincidencia fuzzy con \"{$bestName}\" ({$scoreLabel}%), candidato siguiente al {$secondScore}%.",
                    ];
                }

                $lendusRecord = $lendusIndex['byName'][$bestName] ?? null;
                if ($lendusRecord && $lendusRecord->inferred_branch_id) {
                    $scoreLabel = round($bestScore, 1);

                    return [
                        'branch_id' => (int) $lendusRecord->inferred_branch_id, 'source_type' => SourceType::Lendus,
                        'source_reference' => 'lendus_employee_directory (fuzzy)', 'match_type' => MatchType::Fuzzy,
                        'confidence' => round($bestScore / 100, 2), 'stat_key' => 'fuzzy',
                        'notes' => "Coincidencia fuzzy con directorio Lendus \"{$bestName}\" ({$scoreLabel}%).",
                    ];
                }
            } elseif ($bestName && $bestScore >= 70.0) {
                $ambiguous = true; // there's a plausible-but-not-confident candidate — flag, don't guess
            }
        }

        if ($ambiguous) {
            return [
                'branch_id' => null,
                'stat_key'  => 'ambiguous',
                'notes'     => 'Se detectaron varias sucursales candidatas sin mayoría clara (cobranza, colocación, cartera o gastos empatados, o similitud de nombre insuficiente). Requiere revisión manual.',
            ];
        }

        return null;
    }

    private function resolveCoveredWeekIds(Period $period): array
    {
        if ($period->type === 'weekly') {
            return [$period->id];
        }

        return Period::query()
            ->where('type', 'weekly')
            ->whereDate('start_date', '<=', $period->end_date)
            ->whereDate('end_date', '>=', $period->start_date)
            ->orderBy('start_date')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function resolveUploadIdsForSource(string $sourceCode, array $coveredWeekIds): array
    {
        if (empty($coveredWeekIds)) {
            return [];
        }

        return ReportUpload::query()
            ->whereHas('dataSource', fn ($query) => $query->where('code', $sourceCode))
            ->where(function ($query) use ($coveredWeekIds) {
                foreach ($coveredWeekIds as $weekId) {
                    $query->orWhereJsonContains('covered_period_ids', $weekId);
                }
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function resolveEmployeesForPeriod(Period $period, array $noiUploadIds): Collection
    {
        $employeeIds = collect();

        if (!empty($noiUploadIds)) {
            $employeeIds = $employeeIds->merge(
                NoiMovement::query()
                    ->whereIn('report_upload_id', $noiUploadIds)
                    ->whereNotNull('employee_id')
                    ->pluck('employee_id')
            );
        } else {
            $employeeIds = $employeeIds->merge(
                NoiMovement::query()
                    ->where('period_id', $period->id)
                    ->whereNotNull('employee_id')
                    ->pluck('employee_id')
            );
        }

        $employeeIds = $employeeIds->filter()->unique()->values();

        if ($employeeIds->isEmpty()) {
            return collect();
        }

        return Employee::query()
            ->whereIn('id', $employeeIds)
            ->orderBy('full_name')
            ->get();
    }

    /**
     * Aggregate the full cobranza row set (tens of thousands of rows) into a
     * normalized-name → [branch_id => count] map, computed exactly once per period.
     */
    private function buildCobranzaIndex(array $cobranzaUploadIds, array $operativeNames): array
    {
        if (empty($cobranzaUploadIds)) {
            return ['byName' => [], 'distinctNames' => []];
        }

        $rows = DB::table('fact_recoveries as r')
            ->join('branches as b', 'r.branch_id', '=', 'b.id')
            ->whereIn('r.report_upload_id', $cobranzaUploadIds)
            ->whereNotNull('r.branch_id')
            ->whereIn(DB::raw('UPPER(TRIM(b.name))'), $operativeNames)
            ->select('r.raw_payload', 'r.branch_id')
            ->get();

        $byName = [];

        foreach ($rows as $row) {
            $payload = is_string($row->raw_payload) ? json_decode($row->raw_payload, true) : (array) $row->raw_payload;
            $promoterRaw = $payload['promoter_name'] ?? null;
            if (!$promoterRaw) {
                continue;
            }

            $norm = self::normalizeHumanName($promoterRaw);
            if ($norm === '') {
                continue;
            }

            $byName[$norm][$row->branch_id] = ($byName[$norm][$row->branch_id] ?? 0) + 1;
        }

        return ['byName' => $byName, 'distinctNames' => array_keys($byName)];
    }

    private function buildPlacementsIndex(int $periodId): array
    {
        $rows = DB::table('fact_placements as p')
            ->where('p.period_id', $periodId)
            ->whereNotNull('p.branch_id')
            ->whereNotNull('p.normalized_promoter_name')
            ->selectRaw('p.normalized_promoter_name, p.branch_id, COUNT(*) as cnt')
            ->groupBy('p.normalized_promoter_name', 'p.branch_id')
            ->get();

        $byName = [];
        foreach ($rows as $r) {
            $byName[$r->normalized_promoter_name][] = ['branch_id' => (int) $r->branch_id, 'cnt' => (int) $r->cnt];
        }
        foreach ($byName as $k => $list) {
            usort($list, fn ($a, $b) => $b['cnt'] <=> $a['cnt']);
            $byName[$k] = $list;
        }

        return ['byName' => $byName, 'distinctNames' => array_keys($byName)];
    }

    private function buildPortfoliosIndex(int $periodId, array $operativeNames): array
    {
        $rows = DB::table('fact_portfolios as p')
            ->join('branches as b', 'p.branch_id', '=', 'b.id')
            ->where('p.period_id', $periodId)
            ->whereNotNull('p.branch_id')
            ->whereNotNull('p.promoter_name')
            ->whereIn(DB::raw('UPPER(TRIM(b.name))'), $operativeNames)
            ->selectRaw('p.promoter_name, p.branch_id, COUNT(*) as cnt')
            ->groupBy('p.promoter_name', 'p.branch_id')
            ->get();

        $byName = [];
        foreach ($rows as $r) {
            $norm = self::normalizeHumanName($r->promoter_name);
            if ($norm === '') {
                continue;
            }
            $byName[$norm][] = ['branch_id' => (int) $r->branch_id, 'cnt' => (int) $r->cnt];
        }
        foreach ($byName as $k => $list) {
            usort($list, fn ($a, $b) => $b['cnt'] <=> $a['cnt']);
            $byName[$k] = $list;
        }

        return ['byName' => $byName];
    }

    private function buildExpensesIndex(int $periodId, array $employeeIds, array $operativeNames): array
    {
        if (empty($employeeIds)) {
            return ['byEmployee' => []];
        }

        $rows = DB::table('fact_expenses as e')
            ->join('branches as b', 'e.branch_id', '=', 'b.id')
            ->where('e.period_id', $periodId)
            ->whereIn('e.employee_id', $employeeIds)
            ->whereNotNull('e.branch_id')
            ->whereIn(DB::raw('UPPER(TRIM(b.name))'), $operativeNames)
            ->selectRaw('e.employee_id, e.branch_id, COUNT(*) as cnt')
            ->groupBy('e.employee_id', 'e.branch_id')
            ->get();

        $byEmployee = [];
        foreach ($rows as $r) {
            $byEmployee[$r->employee_id][] = ['branch_id' => (int) $r->branch_id, 'cnt' => (int) $r->cnt];
        }
        foreach ($byEmployee as $k => $list) {
            usort($list, fn ($a, $b) => $b['cnt'] <=> $a['cnt']);
            $byEmployee[$k] = $list;
        }

        return ['byEmployee' => $byEmployee];
    }

    private function buildLendusIndex(): array
    {
        $rows = DB::table('lendus_employee_directory')
            ->where('is_operational', true)
            ->whereNotNull('normalized_name')
            ->get(['codigo', 'nombre', 'normalized_name', 'puesto', 'inferred_branch_id']);

        $byName = [];
        foreach ($rows as $r) {
            $byName[$r->normalized_name] = $r;
        }

        return ['byName' => $byName, 'distinctNames' => array_keys($byName)];
    }

    /**
     * Most recent operative branch_id per employee from a PRIOR period (never the
     * period being processed, to avoid a run feeding off its own not-yet-saved result).
     */
    private function buildHistoricalIndex(int $currentPeriodId, array $employeeIds, array $operativeNames): array
    {
        if (empty($employeeIds)) {
            return [];
        }

        $operativeIds = DB::table('branches')->whereIn(DB::raw('UPPER(TRIM(name))'), $operativeNames)->pluck('id')->all();
        if (empty($operativeIds)) {
            return [];
        }

        $rows = DB::table('employee_branch_assignments')
            ->whereIn('employee_id', $employeeIds)
            ->where('period_id', '!=', $currentPeriodId)
            ->whereNotNull('branch_id')
            ->whereIn('branch_id', $operativeIds)
            ->orderByDesc('period_id')
            ->select('employee_id', 'branch_id')
            ->get();

        $byEmployee = [];
        foreach ($rows as $r) {
            if (!isset($byEmployee[$r->employee_id])) {
                $byEmployee[$r->employee_id] = (int) $r->branch_id; // first hit = most recent (orderByDesc)
            }
        }

        return $byEmployee;
    }

    private function buildAliasIndex(Collection $employees, array $operativeNames): array
    {
        $normNames = $employees->pluck('normalized_name')->filter()->unique()->values()->all();
        if (empty($normNames)) {
            return [];
        }

        $aliasRows = DB::table('employee_aliases')
            ->whereIn('normalized_alias', $normNames)
            ->select('normalized_alias', 'employee_id')
            ->get();

        if ($aliasRows->isEmpty()) {
            return [];
        }

        $operativeIds = DB::table('branches')->whereIn(DB::raw('UPPER(TRIM(name))'), $operativeNames)->pluck('id')->all();
        $canonicalIds = $aliasRows->pluck('employee_id')->unique()->all();

        $branchByCanonical = DB::table('employee_branch_assignments')
            ->whereIn('employee_id', $canonicalIds)
            ->whereNotNull('branch_id')
            ->whereIn('branch_id', $operativeIds)
            ->orderByDesc('period_id')
            ->select('employee_id', 'branch_id')
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($g) => (int) $g->first()->branch_id);

        $byNormName = [];
        foreach ($aliasRows as $a) {
            $branchId = $branchByCanonical[$a->employee_id] ?? null;
            if ($branchId) {
                $byNormName[$a->normalized_alias] = $branchId;
            }
        }

        return $byNormName;
    }

    /**
     * Other Employee rows sharing the same normalized_name (duplicate records from
     * NOI vs NOI Fiscal that were never unified) that already carry a branch.
     */
    private function buildCanonicalIndex(Collection $employees, array $operativeNames): array
    {
        $normNames = $employees->pluck('normalized_name')->filter()->unique()->values()->all();
        if (empty($normNames)) {
            return [];
        }

        $operativeIds = DB::table('branches')->whereIn(DB::raw('UPPER(TRIM(name))'), $operativeNames)->pluck('id')->all();

        $siblings   = DB::table('employees')->whereIn('normalized_name', $normNames)->select('id', 'normalized_name')->get();
        $idsByName  = $siblings->groupBy('normalized_name')->map(fn ($g) => $g->pluck('id')->all());
        $allIds     = $siblings->pluck('id')->all();

        $branchByEmployee = DB::table('employee_branch_assignments')
            ->whereIn('employee_id', $allIds)
            ->whereNotNull('branch_id')
            ->whereIn('branch_id', $operativeIds)
            ->orderByDesc('period_id')
            ->select('employee_id', 'branch_id')
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($g) => (int) $g->first()->branch_id);

        $byNormName = [];
        foreach ($idsByName as $norm => $ids) {
            foreach ($ids as $id) {
                if (isset($branchByEmployee[$id])) {
                    $byNormName[$norm] = $branchByEmployee[$id];
                    break;
                }
            }
        }

        return $byNormName;
    }

    public static function normalizeHumanName(?string $value): string
    {
        return Str::of((string) $value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9\s]/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->value();
    }
}
