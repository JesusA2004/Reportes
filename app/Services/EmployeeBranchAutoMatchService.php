<?php

namespace App\Services;

use App\Enums\MatchType;
use App\Enums\SourceType;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Expense;
use App\Models\NoiMovement;
use App\Models\Period;
use Illuminate\Support\Collection;

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

        foreach ($periods as $period) {
            $result = $this->processPeriod($period);
            $processed += $result['processed'];
            $matched += $result['matched'];
            $unmatched += $result['unmatched'];
        }

        return [
            'processed' => $processed,
            'matched' => $matched,
            'unmatched' => $unmatched,
        ];
    }

    private function processPeriod(Period $period): array
    {
        $employees = Employee::query()
            ->where(function ($query) use ($period) {
                $query->whereIn('id', NoiMovement::query()
                    ->where('period_id', $period->id)
                    ->whereNotNull('employee_id')
                    ->select('employee_id'))
                ->orWhereIn('id', Expense::query()
                    ->where('period_id', $period->id)
                    ->whereNotNull('employee_id')
                    ->select('employee_id'));
            })
            ->get();

        $processed = 0;
        $matched = 0;
        $unmatched = 0;

        foreach ($employees as $employee) {
            $processed++;

            $candidate = $this->resolveBranchCandidate($period, $employee);

            if ($candidate) {
                EmployeeBranchAssignment::query()->updateOrCreate(
                    [
                        'period_id' => $period->id,
                        'employee_id' => $employee->id,
                    ],
                    [
                        'branch_id' => $candidate['branch']->id,
                        'source_type' => SourceType::Lendus,
                        'source_reference' => $candidate['source_reference'],
                        'match_type' => $candidate['match_type'],
                        'confidence' => $candidate['confidence'],
                        'was_manual_reviewed' => false,
                        'notes' => null,
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
                        'notes' => 'No se encontró una sucursal clara para el periodo.',
                    ],
                );

                $unmatched++;
            }
        }

        return [
            'processed' => $processed,
            'matched' => $matched,
            'unmatched' => $unmatched,
        ];
    }

    private function resolveBranchCandidate(Period $period, Employee $employee): ?array
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
            ->map(function (Collection $items, $branchId) {
                /** @var Expense $first */
                $first = $items->first();

                return [
                    'branch' => $first->branch,
                    'count' => $items->count(),
                    'source_reference' => 'fact_expenses',
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

        if ($second && $top['count'] === $second['count']) {
            $matchType = MatchType::Normalized;
            $confidence = 0.6;
        } elseif (($top['count'] ?? 0) === 1) {
            $matchType = MatchType::Normalized;
            $confidence = 0.8;
        }

        return [
            'branch' => $top['branch'],
            'source_reference' => $top['source_reference'],
            'match_type' => $matchType,
            'confidence' => $confidence,
        ];
    }
}
