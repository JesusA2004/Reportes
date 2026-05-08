<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Period;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    // Asignación manual de sucursal a un empleado para un periodo específico.
    // Respeta y marca la asignación como manual_reviewed = true
    // para que futuros cruces automáticos no la sobreescriban.
    public function assignBranch(Employee $employee, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'period_id' => ['required', 'integer', 'exists:periods,id'],
            'notes'     => ['nullable', 'string', 'max:500'],
        ]);

        $branch = Branch::findOrFail($validated['branch_id']);
        $period = Period::findOrFail($validated['period_id']);

        EmployeeBranchAssignment::query()->updateOrCreate(
            [
                'period_id'   => $period->id,
                'employee_id' => $employee->id,
            ],
            [
                'branch_id'           => $branch->id,
                'source_type'         => 'manual',
                'source_reference'    => "Asignación manual — {$period->label}",
                'match_type'          => 'manual',
                'confidence'          => 1.00,
                'was_manual_reviewed' => true,
                'notes'               => $validated['notes'] ?? "Asignado manualmente a {$branch->name}.",
            ]
        );

        return back()->with('success', "Sucursal asignada: {$branch->name} → {$employee->full_name}.");
    }
}
