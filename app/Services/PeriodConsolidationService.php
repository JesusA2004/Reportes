<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Expense;
use App\Models\MonthlyEmployeeSummary;
use App\Models\NoiMovement;
use App\Models\Period;
use Illuminate\Support\Facades\DB;

class PeriodConsolidationService
{
    public function consolidate(Period $period): array
    {
        return DB::transaction(function () use ($period) {
            MonthlyEmployeeSummary::query()
                ->where('period_id', $period->id)
                ->delete();

            $employeeIds = collect()
                ->merge(
                    NoiMovement::query()
                        ->where('period_id', $period->id)
                        ->whereNotNull('employee_id')
                        ->pluck('employee_id')
                )
                ->merge(
                    Expense::query()
                        ->where('period_id', $period->id)
                        ->whereNotNull('employee_id')
                        ->pluck('employee_id')
                )
                ->filter()
                ->unique()
                ->values();

            $created = 0;
            $included = 0;
            $excluded = 0;

            foreach ($employeeIds as $employeeId) {
                $employee = Employee::query()->find($employeeId);

                if (!$employee) {
                    continue;
                }

                $summary = $this->buildEmployeeSummary($period, $employee);

                MonthlyEmployeeSummary::query()->create($summary);

                $created++;

                if ($summary['included_in_report']) {
                    $included++;
                } else {
                    $excluded++;
                }
            }

            return [
                'created' => $created,
                'included' => $included,
                'excluded' => $excluded,
            ];
        });
    }

    private function buildEmployeeSummary(Period $period, Employee $employee): array
    {
        $noiMovements = NoiMovement::query()
            ->where('period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->get();

        $expenses = Expense::query()
            ->where('period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->get();

        $assignment = EmployeeBranchAssignment::query()
            ->where('period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->first();

        $totalPayments = (float) $noiMovements
            ->filter(fn (NoiMovement $movement) => $this->isPayment($movement))
            ->sum('amount');

        $totalBonuses = (float) $noiMovements
            ->filter(fn (NoiMovement $movement) => $this->isBonus($movement))
            ->sum('amount');

        $totalDiscounts = (float) $noiMovements
            ->filter(fn (NoiMovement $movement) => $this->isDiscount($movement))
            ->sum('amount');

        $totalExpenses = (float) $expenses->sum('amount');

        $netAmount = round(($totalPayments + $totalBonuses) - $totalDiscounts - $totalExpenses, 2);

        $hasUsefulMovement = $noiMovements->isNotEmpty() || $expenses->isNotEmpty();

        $includedInReport = $hasUsefulMovement && $assignment?->branch_id !== null;

        $exclusionReason = null;

        if (!$hasUsefulMovement) {
            $exclusionReason = 'Sin movimientos útiles en el periodo.';
        } elseif (!$assignment?->branch_id) {
            $exclusionReason = 'Sin sucursal asignada para el periodo.';
        }

        return [
            'period_id' => $period->id,
            'employee_id' => $employee->id,
            'branch_id' => $assignment?->branch_id,
            'total_payments' => round($totalPayments, 2),
            'total_bonuses' => round($totalBonuses, 2),
            'total_discounts' => round($totalDiscounts, 2),
            'total_expenses' => round($totalExpenses, 2),
            'net_amount' => $netAmount,
            'has_useful_movement' => $hasUsefulMovement,
            'included_in_report' => $includedInReport,
            'exclusion_reason' => $exclusionReason,
        ];
    }

    private function isPayment(NoiMovement $movement): bool
    {
        $type = mb_strtolower((string) ($movement->concept_type ?? ''));
        $concept = mb_strtolower((string) ($movement->concept ?? ''));

        if ($type === 'percepcion') {
            return !str_contains($concept, 'bono');
        }

        return false;
    }

    private function isBonus(NoiMovement $movement): bool
    {
        $type = mb_strtolower((string) ($movement->concept_type ?? ''));
        $concept = mb_strtolower((string) ($movement->concept ?? ''));

        return $type === 'percepcion' && str_contains($concept, 'bono');
    }

    private function isDiscount(NoiMovement $movement): bool
    {
        $type = mb_strtolower((string) ($movement->concept_type ?? ''));

        return in_array($type, ['deduccion', 'descuento'], true);
    }
}
