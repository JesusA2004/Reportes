<?php

namespace App\Services;

use App\Enums\MatchType;
use App\Enums\SourceType;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Expense;
use App\Models\NoiMovement;
use App\Models\Period;
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
        $employees = $this->resolveEmployeesForPeriod($period);

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

            $candidate = $this->resolveBranchCandidate($period, $employee);

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
                        'notes' => 'No se encontró una sucursal clara para el periodo. Requiere revisión manual.',
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

    private function resolveEmployeesForPeriod(Period $period): Collection
    {
        $noiEmployeeIds = NoiMovement::query()
            ->where('period_id', $period->id)
            ->whereNotNull('employee_id')
            ->pluck('employee_id');

        $expenseEmployeeIds = Expense::query()
            ->where('period_id', $period->id)
            ->whereNotNull('employee_id')
            ->pluck('employee_id');

        $employeeIds = $noiEmployeeIds
            ->merge($expenseEmployeeIds)
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

    private function resolveBranchCandidate(Period $period, Employee $employee): ?array
    {
        $expenses = Expense::query()
            ->with('branch:id,name,normalized_name')
            ->where('period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->whereNotNull('branch_id')
            ->get();

        if ($expenses->isNotEmpty()) {
            return $this->resolveCandidateFromExpenses($expenses, $employee);
        }

        return null;
    }

    private function resolveCandidateFromExpenses(Collection $expenses, Employee $employee): ?array
    {
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
        $confidence = 1.0;
        $notes = 'Sucursal asignada automáticamente con base en operación del periodo.';

        if ($second && $top['count'] === $second['count']) {
            $matchType = MatchType::Normalized;
            $confidence = 0.65;
            $notes = 'Se detectó más de una sucursal con el mismo peso. Conviene revisar manualmente.';
        } elseif (($top['count'] ?? 0) === 1) {
            $matchType = MatchType::Normalized;
            $confidence = 0.80;
            $notes = 'Coincidencia operativa con una sola referencia en el periodo. Conviene validar.';
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

    public static function normalizeHumanName(?string $value): string
    {
        $value = Str::of((string) $value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9\s]/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->value();

        return $value;
    }
}
