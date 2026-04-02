<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\EmployeeBranchAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeBranchAssignmentController extends Controller {

    public function index(): Response {
        $assignments = EmployeeBranchAssignment::query()
            ->with([
                'employee:id,full_name,normalized_name',
                'branch:id,name',
                'period:id,year,month,code',
            ])
            ->latest()
            ->get()
            ->map(function (EmployeeBranchAssignment $assignment) {
                $employee = $assignment->employee;
                $branch = $assignment->branch;
                $period = $assignment->period;
                $matchStatus = $assignment->match_status ?? (
                    $assignment->branch_id
                        ? 'matched'
                        : 'pending'
                );
                return [
                    'id' => $assignment->id,
                    'employee_name' => $employee?->full_name ?? 'Sin empleado',
                    'normalized_name' => $employee?->normalized_name,
                    'branch_name' => $branch?->name,
                    'source_name' => $assignment->source_name ?? 'Cruce operativo',
                    'match_status' => $matchStatus,
                    'period_label' => $period?->label,
                    'updated_at' => optional($assignment->updated_at)->format('d/m/Y H:i'),
                ];
            })
            ->values();
        $branches = Branch::query()
            ->orderBy('name')
            ->get(['id', 'name']);
        return Inertia::render('AsignacionSucursal/Index', [
            'assignments' => $assignments,
            'branches' => $branches,
        ]);
    }

    public function pending(): Response {
        $assignments = EmployeeBranchAssignment::query()
            ->with([
                'employee:id,full_name,normalized_name',
                'branch:id,name',
                'period:id,year,month,code',
            ])
            ->where(function ($query) {
                $query->whereNull('branch_id')
                    ->orWhere('match_status', 'pending')
                    ->orWhere('match_status', 'unmatched');
            })
            ->latest()
            ->get()
            ->map(function (EmployeeBranchAssignment $assignment) {
                $employee = $assignment->employee;
                $branch = $assignment->branch;
                $period = $assignment->period;
                return [
                    'id' => $assignment->id,
                    'employee_name' => $employee?->full_name ?? 'Sin empleado',
                    'normalized_name' => $employee?->normalized_name,
                    'branch_name' => $branch?->name,
                    'source_name' => $assignment->source_name ?? 'Cruce operativo',
                    'match_status' => $assignment->match_status ?? 'pending',
                    'period_label' => $period?->label,
                    'updated_at' => optional($assignment->updated_at)->format('d/m/Y H:i'),
                ];
            })
            ->values();
        $branches = Branch::query()
            ->orderBy('name')
            ->get(['id', 'name']);
        return Inertia::render('AsignacionSucursal/Index', [
            'assignments' => $assignments,
            'branches' => $branches,
        ]);
    }

    public function autoMatch(): RedirectResponse {
        // Aquí luego meterás la lógica real del match automático.
        return back()->with('success', 'Match automático ejecutado.');
    }

    public function manualMatch(Request $request, EmployeeBranchAssignment $assignment): RedirectResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
        ], [
            'branch_id.required' => 'Debes seleccionar una sucursal.',
            'branch_id.exists' => 'La sucursal seleccionada no existe.',
        ]);
        $assignment->update([
            'branch_id' => (int) $validated['branch_id'],
            'match_status' => 'manual',
        ]);
        return back()->with('success', 'Asignación manual guardada correctamente.');
    }

    public function update(Request $request, EmployeeBranchAssignment $assignment): RedirectResponse
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'match_status' => ['nullable', 'in:matched,pending,manual,unmatched'],
        ]);
        $assignment->update([
            'branch_id' => $validated['branch_id'] ?? $assignment->branch_id,
            'match_status' => $validated['match_status'] ?? $assignment->match_status,
        ]);
        return back()->with('success', 'Asignación actualizada correctamente.');
    }

}
