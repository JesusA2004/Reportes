<?php

namespace App\Http\Controllers;

use App\Enums\MatchType;
use App\Enums\SourceType;
use App\Models\Branch;
use App\Models\EmployeeBranchAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeBranchAssignmentController extends Controller
{
    public function index(): Response
    {
        $assignments = EmployeeBranchAssignment::query()
            ->with([
                'employee:id,full_name,normalized_name',
                'branch:id,name',
                'period:id,name,code,type,year,month,sequence,start_date,end_date',
            ])
            ->latest()
            ->get()
            ->map(fn (EmployeeBranchAssignment $assignment) => $this->transformAssignment($assignment))
            ->values();

        $branches = Branch::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('AsignacionSucursal/Index', [
            'assignments' => $assignments,
            'branches' => $branches,
        ]);
    }

    public function pending(): Response
    {
        $assignments = EmployeeBranchAssignment::query()
            ->with([
                'employee:id,full_name,normalized_name',
                'branch:id,name',
                'period:id,name,code,type,year,month,sequence,start_date,end_date',
            ])
            ->where(function ($query) {
                $query->whereNull('branch_id')
                    ->orWhere('match_type', MatchType::Unmatched->value);
            })
            ->latest()
            ->get()
            ->map(fn (EmployeeBranchAssignment $assignment) => $this->transformAssignment($assignment))
            ->values();

        $branches = Branch::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('AsignacionSucursal/Index', [
            'assignments' => $assignments,
            'branches' => $branches,
        ]);
    }

    public function autoMatch(): RedirectResponse
    {
        return back()->with('warning', 'El match automático aún no está implementado con lógica real.');
    }

    public function manualMatch(Request $request, EmployeeBranchAssignment $assignment): RedirectResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'branch_id.required' => 'Debes seleccionar una sucursal.',
            'branch_id.exists' => 'La sucursal seleccionada no existe.',
        ]);

        $assignment->update([
            'branch_id' => (int) $validated['branch_id'],
            'source_type' => SourceType::Manual,
            'source_reference' => null,
            'match_type' => MatchType::Manual,
            'confidence' => 1,
            'was_manual_reviewed' => true,
            'notes' => $validated['notes'] ?? $assignment->notes,
        ]);

        return back()->with('success', 'Asignación manual guardada correctamente.');
    }

    public function update(Request $request, EmployeeBranchAssignment $assignment): RedirectResponse
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'match_type' => ['nullable', 'in:exact,normalized,manual,unmatched'],
            'confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'was_manual_reviewed' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'match_type.in' => 'El tipo de match no es válido.',
            'confidence.numeric' => 'La confianza debe ser numérica.',
            'confidence.min' => 'La confianza mínima es 0.',
            'confidence.max' => 'La confianza máxima es 1.',
        ]);

        $newBranchId = array_key_exists('branch_id', $validated)
            ? $validated['branch_id']
            : $assignment->branch_id;

        $newMatchType = array_key_exists('match_type', $validated)
            ? MatchType::from($validated['match_type'])
            : $assignment->match_type;

        $payload = [
            'branch_id' => $newBranchId,
            'match_type' => $newMatchType,
            'confidence' => $validated['confidence'] ?? $assignment->confidence,
            'was_manual_reviewed' => $validated['was_manual_reviewed'] ?? $assignment->was_manual_reviewed,
            'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $assignment->notes,
        ];

        if ($newMatchType === MatchType::Manual || ($assignment->source_type?->value === SourceType::Manual->value)) {
            $payload['source_type'] = SourceType::Manual;
        }

        $assignment->update($payload);

        return back()->with('success', 'Asignación actualizada correctamente.');
    }

    private function transformAssignment(EmployeeBranchAssignment $assignment): array
    {
        $employee = $assignment->employee;
        $branch = $assignment->branch;
        $period = $assignment->period;
        $matchType = $assignment->match_type?->value ?? null;
        $sourceType = $assignment->source_type?->value ?? null;

        return [
            'id' => $assignment->id,
            'employee_name' => $employee?->full_name ?? 'Sin empleado',
            'normalized_name' => $employee?->normalized_name,
            'branch_name' => $branch?->name,
            'source_name' => $this->formatSourceType($sourceType),
            'source_reference' => $assignment->source_reference,
            'match_type' => $matchType,
            'confidence' => $assignment->confidence !== null ? (float) $assignment->confidence : null,
            'was_manual_reviewed' => (bool) $assignment->was_manual_reviewed,
            'ui_status' => $this->resolveUiStatus($assignment),
            'period_label' => $period?->label,
            'updated_at' => optional($assignment->updated_at)->format('d/m/Y H:i'),
            'notes' => $assignment->notes,
        ];
    }

    private function resolveUiStatus(EmployeeBranchAssignment $assignment): string
    {
        $matchType = $assignment->match_type?->value;

        if ($assignment->branch_id && $matchType === MatchType::Manual->value) {
            return 'manual';
        }

        if ($assignment->branch_id) {
            return 'matched';
        }

        if ($matchType === MatchType::Unmatched->value) {
            return 'unmatched';
        }

        return 'pending';
    }

    private function formatSourceType(?string $sourceType): string
    {
        return match ($sourceType) {
            SourceType::Noi->value => 'NOI',
            SourceType::Lendus->value => 'Lendus',
            SourceType::Manual->value => 'Manual',
            default => 'Cruce operativo',
        };
    }
}
