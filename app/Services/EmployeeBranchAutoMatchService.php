<?php

namespace App\Services;

use App\Enums\MatchType;
use App\Enums\SourceType;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Expense;
use App\Models\NoiMovement;
use App\Models\Period;
use App\Models\Recovery;
use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use App\Services\PersonIdentityResolverService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmployeeBranchAutoMatchService
{
    public function __construct(
        private PersonIdentityResolverService $resolver,
        private BranchResolverService $branchResolver,
    ) {}

    public function handle(?int $periodId = null): array
    {
        $periods = Period::query()
            ->when($periodId, fn ($query) => $query->whereKey($periodId))
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderByDesc('sequence')
            ->get();

        $processed = 0;
        $matched = 0;
        $unmatched = 0;
        $manualKept = 0;

        foreach ($periods as $period) {
            $result = $this->processPeriod($period);

            $processed += $result['processed'];
            $matched += $result['matched'];
            $unmatched += $result['unmatched'];
            $manualKept += $result['manual_kept'];
        }

        return [
            'processed' => $processed,
            'matched' => $matched,
            'unmatched' => $unmatched,
            'manual_kept' => $manualKept,
        ];
    }

    private function processPeriod(Period $period): array
    {
        $coveredWeekIds     = $this->resolveCoveredWeekIds($period);
        $noiUploadIds       = $this->resolveUploadIdsForSource('noi_nomina', $coveredWeekIds);
        $fiscalUploadIds    = $this->resolveUploadIdsForSource('noi_nomina_fiscal', $coveredWeekIds);
        $cobranzaUploadIds  = $this->resolveUploadIdsForSource('lendus_ingresos_cobranza', $coveredWeekIds);

        $allNoiUploadIds    = array_values(array_unique(array_merge($noiUploadIds, $fiscalUploadIds)));
        $employees = $this->resolveEmployeesForPeriod($period, $allNoiUploadIds);

        $processed = 0;
        $matched = 0;
        $unmatched = 0;
        $manualKept = 0;

        foreach ($employees as $employee) {
            $processed++;

            $existing = EmployeeBranchAssignment::query()
                ->where('period_id', $period->id)
                ->where('employee_id', $employee->id)
                ->first();

            if (
                $existing &&
                $existing->was_manual_reviewed &&
                $existing->match_type?->value === MatchType::Manual->value &&
                $existing->branch_id
            ) {
                $manualKept++;
                continue;
            }

            $candidate = $this->resolveBranchCandidate($period, $employee, $cobranzaUploadIds);

            if (!$candidate) {
                $candidate = $this->resolveCandidateFromPlacements($period, $employee);
            }

            if (!$candidate) {
                $candidate = $this->resolveCandidateFromPortfolios($period, $employee);
            }

            if (!$candidate) {
                $candidate = $this->resolveCandidateFromExpenses($period, $employee);
            }

            if (!$candidate) {
                $candidate = $this->resolveCandidateFromLendusDirectory($employee);
            }

            // Historical fallback: re-use the most recent assignment from a prior period
            if (!$candidate) {
                $historicalBranchId = $this->resolver->resolveBranchFromExistingAssignments($employee->id);
                if ($historicalBranchId) {
                    $candidate = [
                        'branch_id'        => $historicalBranchId,
                        'source_type'      => SourceType::Lendus,
                        'source_reference' => 'employee_branch_assignments',
                        'match_type'       => MatchType::Normalized,
                        'confidence'       => 0.70,
                        'notes'            => 'Sucursal heredada de asignación histórica de un periodo anterior.',
                    ];
                }
            }

            // Alias fallback: if this person was confirmed as an alias of another employee
            if (!$candidate) {
                $aliasBranchId = $this->resolver->resolveBranchFromAlias($employee);
                if ($aliasBranchId) {
                    $candidate = [
                        'branch_id'        => $aliasBranchId,
                        'source_type'      => SourceType::Lendus,
                        'source_reference' => 'employee_aliases',
                        'match_type'       => MatchType::Normalized,
                        'confidence'       => 0.95,
                        'notes'            => 'Sucursal heredada de alias de persona confirmado.',
                    ];
                }
            }

            // Canonical same-name fallback: another employee record with identical normalized name already has a branch
            if (!$candidate) {
                $canonicalBranchId = $this->resolver->resolveBranchFromCanonicalEmployee($employee);
                if ($canonicalBranchId) {
                    $candidate = [
                        'branch_id'        => $canonicalBranchId,
                        'source_type'      => SourceType::Lendus,
                        'source_reference' => 'employees.normalized_name',
                        'match_type'       => MatchType::CanonicalSameName,
                        'confidence'       => 0.98,
                        'notes'            => 'Sucursal heredada de otro registro con el mismo nombre normalizado.',
                    ];
                }
            }

            if ($candidate) {
                EmployeeBranchAssignment::query()->updateOrCreate(
                    [
                        'period_id' => $period->id,
                        'employee_id' => $employee->id,
                    ],
                    [
                        'branch_id' => $candidate['branch_id'],
                        'source_type' => $candidate['source_type'],
                        'source_reference' => $candidate['source_reference'],
                        'match_type' => $candidate['match_type'],
                        'confidence' => $candidate['confidence'],
                        'was_manual_reviewed' => false,
                        'notes' => $candidate['notes'],
                    ],
                );

                $matched++;
            } else {
                EmployeeBranchAssignment::query()->updateOrCreate(
                    [
                        'period_id' => $period->id,
                        'employee_id' => $employee->id,
                    ],
                    [
                        'branch_id' => null,
                        'source_type' => SourceType::Lendus,
                        'source_reference' => null,
                        'match_type' => MatchType::Unmatched,
                        'confidence' => 0,
                        'was_manual_reviewed' => false,
                        'notes' => 'No se encontró sucursal clara para el colaborador en cobranza u operación. Requiere revisión manual.',
                    ],
                );

                $unmatched++;
            }
        }

        return [
            'processed' => $processed,
            'matched' => $matched,
            'unmatched' => $unmatched,
            'manual_kept' => $manualKept,
        ];
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

        $employeeIds = $employeeIds
            ->filter()
            ->unique()
            ->values();

        if ($employeeIds->isEmpty()) {
            return collect();
        }

        return Employee::query()
            ->whereIn('id', $employeeIds)
            ->orderBy('full_name')
            ->get();
    }

    private function resolveBranchCandidate(Period $period, Employee $employee, array $cobranzaUploadIds): ?array
    {
        if (empty($cobranzaUploadIds)) {
            return null;
        }

        $recoveries = Recovery::query()
            ->with('branch:id,name,normalized_name')
            ->whereIn('report_upload_id', $cobranzaUploadIds)
            ->whereNotNull('branch_id')
            ->get();

        if ($recoveries->isEmpty()) {
            return null;
        }

        $normalizedEmployee = self::normalizeHumanName($employee->full_name);

        $matches = $recoveries->filter(function (Recovery $recovery) use ($normalizedEmployee) {
            $payload = $recovery->raw_payload ?? [];

            // Prefer pre-normalized key; fall back to raw promoter_name normalized on the fly.
            $promoter = $payload['__promoter_normalized']
                ?? (isset($payload['promoter_name'])
                    ? self::normalizeHumanName($payload['promoter_name'])
                    : null);

            if (!$promoter) {
                return false;
            }

            if ($promoter === $normalizedEmployee) {
                return true;
            }

            return $this->isLikelySamePerson($promoter, $normalizedEmployee);
        });

        if ($matches->isEmpty()) {
            return null;
        }

        // Only operative branches — filter out routes, zones, and non-operative locations
        $operativeNames = array_map('strtoupper', $this->branchResolver->operativeFinancialBranches());

        $grouped = $matches
            ->filter(fn (Recovery $recovery) => $recovery->branch !== null)
            ->filter(fn (Recovery $recovery) => in_array(
                strtoupper(trim($recovery->branch->name ?? '')),
                $operativeNames,
                true,
            ))
            ->groupBy('branch_id')
            ->map(function (Collection $items) use ($employee) {
                /** @var Recovery $first */
                $first = $items->first();

                return [
                    'branch_id' => $first->branch_id,
                    'branch_name' => $first->branch?->name,
                    'count' => $items->count(),
                    'employee_name' => $employee->full_name,
                    'normalized_name' => $employee->normalized_name,
                ];
            })
            ->sortByDesc('count')
            ->values();

        if ($grouped->isEmpty()) {
            return null;
        }

        $top = $grouped->first();
        $second = $grouped->get(1);

        $matchType = MatchType::Exact;
        $confidence = 1.00;
        $notes = 'Sucursal asignada automáticamente con base en cobranza de Lendus del periodo.';

        if ($second && $top['count'] === $second['count']) {
            $matchType = MatchType::Normalized;
            $confidence = 0.65;
            $notes = 'Se detectó más de una sucursal con el mismo peso en cobranza. Conviene revisar manualmente.';
        } elseif (($top['count'] ?? 0) === 1) {
            $matchType = MatchType::Normalized;
            $confidence = 0.82;
            $notes = 'Coincidencia por nombre normalizado del promotor en cobranza. Conviene validar.';
        }

        return [
            'branch_id' => $top['branch_id'],
            'source_type' => SourceType::Lendus,
            'source_reference' => 'fact_recoveries',
            'match_type' => $matchType,
            'confidence' => $confidence,
            'notes' => $notes,
        ];
    }

    private function resolveCandidateFromPlacements(Period $period, Employee $employee): ?array
    {
        $normalizedName = $employee->normalized_name;

        // Load all distinct promoter→branch rows for this period (90 rows max per period)
        $promoterMap = DB::table('fact_placements as p')
            ->join('branches as b', 'p.branch_id', '=', 'b.id')
            ->where('p.period_id', $period->id)
            ->whereNotNull('p.branch_id')
            ->selectRaw('p.normalized_promoter_name, p.promoter_code, p.branch_id, b.name as branch_name, COUNT(*) as cnt')
            ->groupBy('p.normalized_promoter_name', 'p.promoter_code', 'p.branch_id', 'b.name')
            ->orderByDesc('cnt')
            ->get()
            ->groupBy('normalized_promoter_name');

        if ($promoterMap->isEmpty()) {
            return null;
        }

        // 1. Exact name match
        $exactRows = $promoterMap->get($normalizedName);
        if ($exactRows && $exactRows->isNotEmpty()) {
            return $this->buildPlacementCandidate($exactRows->sortByDesc('cnt')->first(), 0.90, 'Sucursal asignada con base en colocación del periodo.');
        }

        // 2. Fuzzy fallback — accept only very high similarity (≥95%) to catch single-character typos
        $bestScore = 0.0;
        $bestRow   = null;
        foreach ($promoterMap as $pname => $rows) {
            similar_text($normalizedName, $pname, $pct);
            if ($pct > $bestScore) {
                $bestScore = $pct;
                $bestRow   = $rows->sortByDesc('cnt')->first();
            }
        }

        if ($bestScore >= 95.0 && $bestRow) {
            $scoreLabel = round($bestScore, 1);
            return $this->buildPlacementCandidate($bestRow, 0.85, "Sucursal inferida por colocación (variante de nombre, similitud {$scoreLabel}%). Código promotor: {$bestRow->promoter_code}.");
        }

        return null;
    }

    private function buildPlacementCandidate(object $row, float $confidence, string $notes): array
    {
        return [
            'branch_id'        => $row->branch_id,
            'source_type'      => SourceType::Lendus,
            'source_reference' => 'fact_placements',
            'match_type'       => MatchType::Normalized,
            'confidence'       => $confidence,
            'notes'            => $notes,
        ];
    }

    private function resolveCandidateFromPortfolios(Period $period, Employee $employee): ?array
    {
        $rows = DB::table('fact_portfolios as p')
            ->join('branches as b', 'p.branch_id', '=', 'b.id')
            ->where('p.period_id', $period->id)
            ->whereRaw('LOWER(p.promoter_name) = ?', [$employee->normalized_name])
            ->whereNotNull('p.branch_id')
            ->selectRaw('p.branch_id, b.name as branch_name, COUNT(*) as cnt, SUM(p.balance) as balance')
            ->groupBy('p.branch_id', 'b.name')
            ->orderByDesc('balance')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $top    = $rows->first();
        $second = $rows->get(1);
        $tie    = $second && $top->cnt === $second->cnt;

        return [
            'branch_id'        => $top->branch_id,
            'source_type'      => SourceType::Lendus,
            'source_reference' => 'fact_portfolios',
            'match_type'       => $tie ? MatchType::Normalized : MatchType::Normalized,
            'confidence'       => $tie ? 0.65 : 0.82,
            'notes'            => $tie
                ? 'Sucursal inferida por cartera (empate). Conviene validar.'
                : 'Sucursal asignada con base en cartera del periodo.',
        ];
    }

    private function resolveCandidateFromExpenses(Period $period, Employee $employee): ?array
    {
        $expenses = Expense::query()
            ->with('branch:id,name,normalized_name')
            ->where('period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->whereNotNull('branch_id')
            ->get();

        if ($expenses->isEmpty()) {
            return null;
        }

        $grouped = $expenses
            ->filter(fn (Expense $expense) => $expense->branch !== null)
            ->groupBy('branch_id')
            ->map(function (Collection $items) use ($employee) {
                /** @var Expense $first */
                $first = $items->first();

                return [
                    'branch_id' => $first->branch_id,
                    'branch_name' => $first->branch?->name,
                    'count' => $items->count(),
                    'employee_name' => $employee->full_name,
                    'normalized_name' => $employee->normalized_name,
                ];
            })
            ->sortByDesc('count')
            ->values();

        if ($grouped->isEmpty()) {
            return null;
        }

        $top = $grouped->first();
        $second = $grouped->get(1);

        $matchType = MatchType::Exact;
        $confidence = 1.00;
        $notes = 'Sucursal asignada automáticamente con base en gastos del periodo.';

        if ($second && $top['count'] === $second['count']) {
            $matchType = MatchType::Normalized;
            $confidence = 0.65;
            $notes = 'Se detectó más de una sucursal con el mismo peso en gastos. Conviene revisar manualmente.';
        } elseif (($top['count'] ?? 0) === 1) {
            $matchType = MatchType::Normalized;
            $confidence = 0.80;
            $notes = 'Coincidencia operativa con una sola referencia en gastos. Conviene validar.';
        }

        return [
            'branch_id' => $top['branch_id'],
            'source_type' => SourceType::Lendus,
            'source_reference' => 'fact_expenses',
            'match_type' => $matchType,
            'confidence' => $confidence,
            'notes' => $notes,
        ];
    }

    private function resolveCandidateFromLendusDirectory(Employee $employee): ?array
    {
        $normalized = $employee->normalized_name;
        if (!$normalized) return null;

        // Load all operational directory entries once for both exact and fuzzy checks.
        $allRecords = DB::table('lendus_employee_directory')
            ->where('is_operational', true)
            ->whereNotNull('normalized_name')
            ->get(['id', 'codigo', 'nombre', 'normalized_name', 'puesto', 'inferred_branch_id']);

        if ($allRecords->isEmpty()) return null;

        // ── Step 1: Exact name match ───────────────────────────────────────────
        $record = $allRecords->first(fn ($r) => $r->normalized_name === $normalized);

        // ── Step 2: Fuzzy name match ≥85% ─────────────────────────────────────
        if (!$record) {
            $bestScore  = 0.0;
            $bestRecord = null;
            foreach ($allRecords as $rec) {
                similar_text($normalized, $rec->normalized_name, $pct);
                if ($pct > $bestScore) {
                    $bestScore  = $pct;
                    $bestRecord = $rec;
                }
            }
            if ($bestScore >= 85.0 && $bestRecord) {
                $record = $bestRecord;
            }
        }

        if (!$record) return null;

        $scoreLabel = isset($bestScore) && $bestScore < 100.0 ? " (similitud " . round($bestScore, 1) . "%)" : '';

        // ── Step 3: Use inferred_branch_id if available ────────────────────────
        if ($record->inferred_branch_id) {
            return [
                'branch_id'        => $record->inferred_branch_id,
                'source_type'      => SourceType::Lendus,
                'source_reference' => 'lendus_employee_directory',
                'match_type'       => MatchType::Normalized,
                'confidence'       => 0.88,
                'notes'            => "Sucursal inferida del directorio Lendus{$scoreLabel}. Código: {$record->codigo}, Puesto: {$record->puesto}.",
            ];
        }

        // ── Step 4: Resolve branch directly from CODIGO prefix ─────────────────
        // Handles the case where inferred_branch_id was not set during import
        // (e.g., branch didn't exist in DB at import time).
        if ($record->codigo) {
            $branchName = $this->branchResolver->resolveBranchNameFromCode($record->codigo);
            if ($branchName) {
                $branchId = DB::table('branches')
                    ->whereRaw('UPPER(TRIM(name)) = ?', [strtoupper(trim($branchName))])
                    ->value('id');
                if ($branchId) {
                    return [
                        'branch_id'        => (int) $branchId,
                        'source_type'      => SourceType::Lendus,
                        'source_reference' => 'lendus_employee_directory',
                        'match_type'       => MatchType::Normalized,
                        'confidence'       => 0.85,
                        'notes'            => "Sucursal por código {$record->codigo} → {$branchName}{$scoreLabel}. Puesto: {$record->puesto}.",
                    ];
                }
            }
        }

        return null;
    }

    private function isLikelySamePerson(string $left, string $right): bool
    {
        return $this->resolver->isLikelySamePerson($left, $right, 80.0);
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
