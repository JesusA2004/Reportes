<?php

namespace App\Services;

use App\Models\EmployeeBranchAssignment;
use App\Models\Expense;
use App\Models\MonthlyEmployeeSummary;
use App\Models\NoiMovement;
use App\Models\Period;
use App\Models\ReportUpload;

class PeriodCloseGuardService
{
    public function canClose(Period $period): array
    {
        $issues = [];

        $failedUploads = ReportUpload::query()
            ->where('period_id', $period->id)
            ->where('status', 'failed')
            ->count();

        if ($failedUploads > 0) {
            $issues[] = "Hay {$failedUploads} archivo(s) con error de procesamiento.";
        }

        $unmatchedAssignments = EmployeeBranchAssignment::query()
            ->where('period_id', $period->id)
            ->where(function ($query) {
                $query->whereNull('branch_id')
                    ->orWhere('match_type', 'unmatched');
            })
            ->count();

        if ($unmatchedAssignments > 0) {
            $issues[] = "Hay {$unmatchedAssignments} asignación(es) de sucursal pendiente(s) o sin match.";
        }

        $noiWithoutAssignment = NoiMovement::query()
            ->where('period_id', $period->id)
            ->whereNotNull('employee_id')
            ->get()
            ->filter(function (NoiMovement $movement) use ($period) {
                return !EmployeeBranchAssignment::query()
                    ->where('period_id', $period->id)
                    ->where('employee_id', $movement->employee_id)
                    ->whereNotNull('branch_id')
                    ->exists();
            })
            ->count();

        if ($noiWithoutAssignment > 0) {
            $issues[] = "Hay {$noiWithoutAssignment} movimiento(s) NOI con empleado sin sucursal asignada.";
        }

        $expensesWithoutEmployee = Expense::query()
            ->where('period_id', $period->id)
            ->whereNull('employee_id')
            ->count();

        if ($expensesWithoutEmployee > 0) {
            $issues[] = "Hay {$expensesWithoutEmployee} gasto(s) sin empleado relacionado.";
        }

        $expensesWithoutBranch = Expense::query()
            ->where('period_id', $period->id)
            ->whereNull('branch_id')
            ->count();

        if ($expensesWithoutBranch > 0) {
            $issues[] = "Hay {$expensesWithoutBranch} gasto(s) sin sucursal relacionada.";
        }

        $excludedSummaries = MonthlyEmployeeSummary::query()
            ->where('period_id', $period->id)
            ->where('included_in_report', false)
            ->count();

        if ($excludedSummaries > 0) {
            $issues[] = "Hay {$excludedSummaries} fila(s) excluida(s) del consolidado.";
        }

        return [
            'can_close' => count($issues) === 0,
            'issues' => $issues,
        ];
    }
}
