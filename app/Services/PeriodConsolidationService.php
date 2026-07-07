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
        // Fact data (NOI, Expenses) is stored under the period actually uploaded to — for
        // monthly periods that's the monthly period_id itself, not its weekly components.
        // Compound periods may ALSO have data on their base weekly IDs, so include both.
        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge($weeklyIds, [$period->id])));

        return DB::transaction(function () use ($period, $dataIds) {
            MonthlyEmployeeSummary::query()
                ->where('period_id', $period->id)
                ->delete();

            $employeeIds = collect()
                ->merge(
                    NoiMovement::query()
                        ->whereIn('period_id', $dataIds)
                        ->whereNotNull('employee_id')
                        ->pluck('employee_id')
                )
                ->merge(
                    Expense::query()
                        ->whereIn('period_id', $dataIds)
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

                $summary = $this->buildEmployeeSummary($period, $dataIds, $employee);

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

    private function buildEmployeeSummary(Period $period, array $dataIds, Employee $employee): array
    {
        $noiMovements = NoiMovement::query()
            ->whereIn('period_id', $dataIds)
            ->where('employee_id', $employee->id)
            ->get();

        $expenses = Expense::query()
            ->whereIn('period_id', $dataIds)
            ->where('employee_id', $employee->id)
            ->get();

        // Look for assignment on the reporting period first; fall back to any base weekly period
        $assignment = EmployeeBranchAssignment::query()
            ->where('employee_id', $employee->id)
            ->whereIn('period_id', array_merge([$period->id], $dataIds))
            ->whereNotNull('branch_id')
            ->orderByRaw('CASE WHEN period_id = ? THEN 0 ELSE 1 END', [$period->id])
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

        $netAmount = round(($totalPayments + $totalBonuses) - $totalDiscounts, 2);

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
        $type    = $this->normalizeConceptType((string) ($movement->concept_type ?? ''));
        $concept = mb_strtolower((string) ($movement->concept ?? ''));

        if ($type === 'percepcion') {
            return !str_contains($concept, 'bono');
        }

        // Comisiones cuentan como pago del gestor aunque concept_type sea null/desconocido
        if (str_contains($concept, 'comisi')) {
            return true;
        }

        return false;
    }

    private function isBonus(NoiMovement $movement): bool
    {
        $type    = $this->normalizeConceptType((string) ($movement->concept_type ?? ''));
        $concept = mb_strtolower((string) ($movement->concept ?? ''));

        return $type === 'percepcion' && str_contains($concept, 'bono');
    }

    private function isDiscount(NoiMovement $movement): bool
    {
        $type = $this->normalizeConceptType((string) ($movement->concept_type ?? ''));

        return in_array($type, ['deduccion', 'descuento'], true);
    }

    private function normalizeConceptType(string $type): string
    {
        $type = mb_strtolower(trim($type));
        return str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $type,
        );
    }
}
