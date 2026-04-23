<?php

namespace App\Http\Controllers;

use App\Enums\MatchType;
use App\Enums\SourceType;
use App\Models\Branch;
use App\Models\EmployeeBranchAssignment;
use App\Models\Period;
use App\Services\EmployeeBranchAutoMatchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeBranchAssignmentController extends Controller
{
    public function index(Request $request): Response
    {
        $periods = Period::query()
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderByDesc('sequence')
            ->get(['id', 'name', 'code', 'type', 'year', 'month', 'sequence', 'start_date', 'end_date']);

        $selectedPeriod = $periods->firstWhere('id', (int) $request->integer('period_id'))
            ?? $periods->first();

        $assignments = collect();
        $branches = Branch::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $summary = [
            'total' => 0,
            'matched' => 0,
            'manual' => 0,
            'pending' => 0,
            'unmatched' => 0,
            'with_branch' => 0,
            'without_branch' => 0,
            'high_confidence' => 0,
            'needs_review' => 0,
            'hires' => 0,
            'leavers' => 0,
        ];

        $hires = collect();
        $leavers = collect();
        $incidences = collect();

        if ($selectedPeriod) {
            $assignments = EmployeeBranchAssignment::query()
                ->with([
                    'employee:id,full_name,normalized_name',
                    'branch:id,name',
                    'period:id,name,code,type,year,month,sequence,start_date,end_date',
                ])
                ->where('period_id', $selectedPeriod->id)
                ->orderByDesc('updated_at')
                ->get()
                ->map(fn (EmployeeBranchAssignment $assignment) => $this->transformAssignment($assignment))
                ->values();

            $previousPeriod = Period::query()
                ->where('id', '!=', $selectedPeriod->id)
                ->whereDate('start_date', '<', $selectedPeriod->start_date)
                ->orderByDesc('start_date')
                ->orderByDesc('id')
                ->first();

            $previousAssignments = collect();

            if ($previousPeriod) {
                $previousAssignments = EmployeeBranchAssignment::query()
                    ->with(['employee:id,full_name,normalized_name', 'branch:id,name', 'period:id,name'])
                    ->where('period_id', $previousPeriod->id)
                    ->get();
            }

            $currentEmployeeIds = $assignments->pluck('employee_id')->filter()->unique()->values();
            $previousEmployeeIds = $previousAssignments->pluck('employee_id')->filter()->unique()->values();

            $hireIds = $currentEmployeeIds->diff($previousEmployeeIds)->values();
            $leaverIds = $previousEmployeeIds->diff($currentEmployeeIds)->values();

            $hires = $assignments
                ->whereIn('employee_id', $hireIds)
                ->values();

            $leavers = $previousAssignments
                ->whereIn('employee_id', $leaverIds)
                ->map(fn (EmployeeBranchAssignment $assignment) => $this->transformAssignment($assignment, 'baja'))
                ->values();

            $incidences = $assignments
                ->filter(function (array $item) {
                    return in_array($item['ui_status'], ['pending', 'unmatched'], true)
                        || $item['needs_manual_attention']
                        || !$item['branch_id'];
                })
                ->values();

            $summary = [
                'total' => $assignments->count(),
                'matched' => $assignments->where('ui_status', 'matched')->count(),
                'manual' => $assignments->where('ui_status', 'manual')->count(),
                'pending' => $assignments->where('ui_status', 'pending')->count(),
                'unmatched' => $assignments->where('ui_status', 'unmatched')->count(),
                'with_branch' => $assignments->whereNotNull('branch_id')->count(),
                'without_branch' => $assignments->whereNull('branch_id')->count(),
                'high_confidence' => $assignments->filter(fn ($item) => ($item['confidence'] ?? 0) >= 0.9)->count(),
                'needs_review' => $incidences->count(),
                'hires' => $hires->count(),
                'leavers' => $leavers->count(),
            ];
        }

        return Inertia::render('AsignacionSucursal/Index', [
            'assignments' => $assignments,
            'branches' => $branches,
            'periods' => $periods->map(fn (Period $period) => [
                'id' => $period->id,
                'label' => $period->label,
                'type' => $period->type,
                'start_date' => optional($period->start_date)->format('Y-m-d'),
                'end_date' => optional($period->end_date)->format('Y-m-d'),
            ])->values(),
            'selected_period_id' => $selectedPeriod?->id,
            'selected_period_label' => $selectedPeriod?->label,
            'summary' => $summary,
            'incidences' => $incidences,
            'hires' => $hires,
            'leavers' => $leavers,
        ]);
    }

    public function pending(Request $request): Response
    {
        $request->merge(['only_pending' => true]);

        return $this->index($request);
    }

    public function autoMatch(Request $request, EmployeeBranchAutoMatchService $service): RedirectResponse
    {
        $validated = $request->validate([
            'period_id' => ['nullable', 'integer', 'exists:periods,id'],
        ]);

        $result = $service->handle($validated['period_id'] ?? null);

        return back()->with(
            'success',
            "Cruce ejecutado. Procesados: {$result['processed']}, match: {$result['matched']}, sin match: {$result['unmatched']}, manuales respetados: {$result['manual_kept']}."
        );
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

        if (
            $newMatchType === MatchType::Manual ||
            ($assignment->source_type?->value === SourceType::Manual->value)
        ) {
            $payload['source_type'] = SourceType::Manual;
        }

        $assignment->update($payload);

        return back()->with('success', 'Asignación actualizada correctamente.');
    }

    private function transformAssignment(EmployeeBranchAssignment $assignment, string $context = 'actual'): array
    {
        $employee = $assignment->employee;
        $branch = $assignment->branch;
        $period = $assignment->period;
        $matchType = $assignment->match_type?->value ?? null;
        $sourceType = $assignment->source_type?->value ?? null;
        $uiStatus = $this->resolveUiStatus($assignment);

        return [
            'id' => $assignment->id,
            'employee_id' => $assignment->employee_id,
            'branch_id' => $assignment->branch_id,
            'employee_name' => $employee?->full_name ?? 'Sin empleado',
            'normalized_name' => $employee?->normalized_name,
            'branch_name' => $branch?->name,
            'source_name' => $this->formatSourceType($sourceType),
            'source_reference' => $assignment->source_reference,
            'match_type' => $matchType,
            'match_label' => $this->formatMatchType($matchType),
            'match_explanation' => $this->formatMatchExplanation($matchType),
            'confidence' => $assignment->confidence !== null ? (float) $assignment->confidence : null,
            'was_manual_reviewed' => (bool) $assignment->was_manual_reviewed,
            'ui_status' => $uiStatus,
            'period_label' => $period?->label,
            'updated_at' => optional($assignment->updated_at)->format('d/m/Y H:i'),
            'notes' => $assignment->notes,
            'needs_manual_attention' => in_array($uiStatus, ['pending', 'unmatched'], true)
                || (($assignment->confidence ?? 0) < 0.85),
            'context' => $context,
        ];
    }

    private function resolveUiStatus(EmployeeBranchAssignment $assignment): string
    {
        $matchType = $assignment->match_type?->value;

        if ($assignment->branch_id && $matchType === MatchType::Manual->value) {
            return 'manual';
        }

        if ($assignment->branch_id && in_array($matchType, [
            MatchType::Exact->value,
            MatchType::Normalized->value,
        ], true)) {
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

    private function formatMatchType(?string $matchType): string
    {
        return match ($matchType) {
            MatchType::Exact->value => 'Exacto',
            MatchType::Normalized->value => 'Normalizado',
            MatchType::Manual->value => 'Manual',
            MatchType::Unmatched->value => 'Sin match',
            default => 'Pendiente',
        };
    }

    private function formatMatchExplanation(?string $matchType): string
    {
        return match ($matchType) {
            MatchType::Exact->value => 'Coincidencia directa.',
            MatchType::Normalized->value => 'Coincidencia normalizada. Conviene revisar acentos, mayúsculas o variaciones menores.',
            MatchType::Manual->value => 'Asignación validada manualmente.',
            MatchType::Unmatched->value => 'No se logró determinar una sucursal con suficiente confianza.',
            default => 'Pendiente de revisión.',
        };
    }
}
