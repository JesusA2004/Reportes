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
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EmployeeBranchAutoMatchService
{
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
        $coveredWeekIds = $this->resolveCoveredWeekIds($period);
        $noiUploadIds = $this->resolveUploadIdsForSource('noi_nomina', $coveredWeekIds);
        $cobranzaUploadIds = $this->resolveUploadIdsForSource('lendus_ingresos_cobranza', $coveredWeekIds);

        $employees = $this->resolveEmployeesForPeriod($period, $noiUploadIds);

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
                $candidate = $this->resolveCandidateFromExpenses($period, $employee);
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
            $promoter = $payload['__promoter_normalized'] ?? null;

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

        $grouped = $matches
            ->filter(fn (Recovery $recovery) => $recovery->branch !== null)
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

    private function isLikelySamePerson(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }

        similar_text($left, $right, $percent);

        if ($percent >= 88) {
            return true;
        }

        $leftTokens = collect(explode(' ', $left))->filter()->values();
        $rightTokens = collect(explode(' ', $right))->filter()->values();

        if ($leftTokens->isEmpty() || $rightTokens->isEmpty()) {
            return false;
        }

        $intersection = $leftTokens->intersect($rightTokens);

        return $intersection->count() >= min(2, $leftTokens->count(), $rightTokens->count());
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
