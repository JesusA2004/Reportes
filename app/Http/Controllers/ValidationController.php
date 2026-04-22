<?php

namespace App\Http\Controllers;

use App\Enums\MatchType;
use App\Models\EmployeeBranchAssignment;
use App\Models\Expense;
use App\Models\MonthlyEmployeeSummary;
use App\Models\NoiMovement;
use App\Models\Period;
use App\Models\ReportUpload;
use Inertia\Inertia;
use Inertia\Response;

class ValidationController extends Controller {

    public function index(): Response {
        $items = collect();
        /*
        | 1) NOI con empleado pero sin sucursal asignada
        */
        $noiWithoutAssignment = NoiMovement::query()
            ->with([
                'employee:id,full_name',
                'period:id,name,code,type,year,month,sequence,start_date,end_date',
            ])
            ->whereNotNull('employee_id')
            ->get()
            ->filter(function (NoiMovement $movement) {
                return !EmployeeBranchAssignment::query()
                    ->where('period_id', $movement->period_id)
                    ->where('employee_id', $movement->employee_id)
                    ->whereNotNull('branch_id')
                    ->exists();
            });
        foreach ($noiWithoutAssignment as $movement) {
            $items->push([
                'id' => 'noi-no-branch-' . $movement->id,
                'type' => 'NOI sin sucursal',
                'title' => 'Empleado con NOI sin sucursal asignada',
                'description' => 'El empleado tiene movimientos NOI en el periodo, pero no tiene sucursal asignada.',
                'employee_name' => $movement->employee?->full_name,
                'period_label' => $movement->period?->label,
                'severity' => 'high',
                'status' => 'open',
                'updated_at' => optional($movement->updated_at)->format('d/m/Y H:i'),
                'timestamp' => optional($movement->updated_at)?->timestamp ?? 0,
            ]);
        }

        /*
        | 2) Asignaciones pendientes o sin match
        */
        $pendingAssignments = EmployeeBranchAssignment::query()
            ->with([
                'employee:id,full_name',
                'period:id,name,code,type,year,month,sequence,start_date,end_date',
            ])
            ->where(function ($query) {
                $query->whereNull('branch_id')
                    ->orWhere('match_type', MatchType::Unmatched->value);
            })
            ->latest()
            ->get();
        foreach ($pendingAssignments as $assignment) {
            $isUnmatched = $assignment->match_type?->value === MatchType::Unmatched->value;
            $items->push([
                'id' => 'assignment-' . $assignment->id,
                'type' => 'Asignación de sucursal',
                'title' => $isUnmatched
                    ? 'Empleado sin match de sucursal'
                    : 'Empleado sin sucursal asignada',
                'description' => $isUnmatched
                    ? 'No fue posible determinar automáticamente una sucursal para este empleado.'
                    : 'Este registro requiere revisión manual para definir la sucursal correcta.',
                'employee_name' => $assignment->employee?->full_name,
                'period_label' => $assignment->period?->label,
                'severity' => 'high',
                'status' => 'open',
                'updated_at' => optional($assignment->updated_at)->format('d/m/Y H:i'),
                'timestamp' => optional($assignment->updated_at)?->timestamp ?? 0,
            ]);
        }

        /*
        | 3) Gastos sin empleado resuelto
        */
        $expensesWithoutEmployee = Expense::query()
            ->with('period:id,name,code,type,year,month,sequence,start_date,end_date')
            ->whereNull('employee_id')
            ->latest()
            ->get();
        foreach ($expensesWithoutEmployee as $expense) {
            $items->push([
                'id' => 'expense-no-employee-' . $expense->id,
                'type' => 'Gasto sin empleado',
                'title' => 'Gasto sin empleado relacionado',
                'description' => 'Se importó un gasto, pero no se pudo relacionar con ningún empleado.',
                'employee_name' => null,
                'period_label' => $expense->period?->label,
                'severity' => 'medium',
                'status' => 'open',
                'updated_at' => optional($expense->updated_at)->format('d/m/Y H:i'),
                'timestamp' => optional($expense->updated_at)?->timestamp ?? 0,
            ]);
        }

        /*
        | 4) Gastos sin sucursal resuelta
        */
        $expensesWithoutBranch = Expense::query()
            ->with([
                'employee:id,full_name',
                'period:id,name,code,type,year,month,sequence,start_date,end_date',
            ])
            ->whereNull('branch_id')
            ->latest()
            ->get();
        foreach ($expensesWithoutBranch as $expense) {
            $items->push([
                'id' => 'expense-no-branch-' . $expense->id,
                'type' => 'Gasto sin sucursal',
                'title' => 'Gasto sin sucursal relacionada',
                'description' => 'Se importó un gasto, pero no se pudo relacionar con ninguna sucursal.',
                'employee_name' => $expense->employee?->full_name,
                'period_label' => $expense->period?->label,
                'severity' => 'medium',
                'status' => 'open',
                'updated_at' => optional($expense->updated_at)->format('d/m/Y H:i'),
                'timestamp' => optional($expense->updated_at)?->timestamp ?? 0,
            ]);
        }

        /*
        | 5) Archivos con error de procesamiento
        */
        $failedUploads = ReportUpload::query()
            ->with([
                'period:id,name,code,type,year,month,sequence,start_date,end_date',
                'dataSource:id,name',
            ])
            ->where('status', 'failed')
            ->latest()
            ->get();
        foreach ($failedUploads as $upload) {
            $items->push([
                'id' => 'upload-' . $upload->id,
                'type' => 'Carga de archivo',
                'title' => 'Archivo con error de procesamiento',
                'description' => $upload->dataSource?->name
                    ? "La fuente {$upload->dataSource->name} presentó error al procesarse."
                    : 'Un archivo presentó error al procesarse.',
                'employee_name' => null,
                'period_label' => $upload->period?->label,
                'severity' => 'medium',
                'status' => 'open',
                'updated_at' => optional($upload->updated_at)->format('d/m/Y H:i'),
                'timestamp' => optional($upload->updated_at)?->timestamp ?? 0,
            ]);
        }

        /*
        | 6) Resúmenes consolidados excluidos
        */
        $excludedSummaries = MonthlyEmployeeSummary::query()
            ->with([
                'employee:id,full_name',
                'period:id,name,code,type,year,month,sequence,start_date,end_date',
            ])
            ->where('included_in_report', false)
            ->latest()
            ->get();
        foreach ($excludedSummaries as $summary) {
            $items->push([
                'id' => 'summary-excluded-' . $summary->id,
                'type' => 'Resumen excluido',
                'title' => 'Empleado excluido del consolidado',
                'description' => $summary->exclusion_reason ?: 'El empleado fue excluido del reporte consolidado.',
                'employee_name' => $summary->employee?->full_name,
                'period_label' => $summary->period?->label,
                'severity' => 'high',
                'status' => 'open',
                'updated_at' => optional($summary->updated_at)->format('d/m/Y H:i'),
                'timestamp' => optional($summary->updated_at)?->timestamp ?? 0,
            ]);
        }

        /*
        | 7) Periodos con consolidado incompleto
        */
        $periods = Period::query()
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderByDesc('sequence')
            ->get();
        foreach ($periods as $period) {
            $excludedCount = MonthlyEmployeeSummary::query()
                ->where('period_id', $period->id)
                ->where('included_in_report', false)
                ->count();
            if ($excludedCount > 0) {
                $items->push([
                    'id' => 'period-excluded-' . $period->id,
                    'type' => 'Periodo con exclusiones',
                    'title' => 'Periodo consolidado con empleados excluidos',
                    'description' => "El periodo {$period->label} tiene {$excludedCount} fila(s) excluida(s) del reporte final.",
                    'employee_name' => null,
                    'period_label' => $period->label,
                    'severity' => 'medium',
                    'status' => 'open',
                    'updated_at' => optional($period->updated_at)->format('d/m/Y H:i'),
                    'timestamp' => optional($period->updated_at)?->timestamp ?? 0,
                ]);
            }
        }
        return Inertia::render('Validaciones/Index', [
            'validations' => $items
                ->sortByDesc('timestamp')
                ->map(function (array $item) {
                    unset($item['timestamp']);
                    return $item;
                })
                ->values(),
        ]);
    }

}
