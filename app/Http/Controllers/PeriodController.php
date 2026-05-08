<?php

namespace App\Http\Controllers;

use App\Models\DataSource;
use App\Models\Period;
use App\Services\PeriodCloseGuardService;
use App\Services\PeriodGenerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PeriodController extends Controller
{
    public function index(PeriodCloseGuardService $guard): Response
    {
        $requiredSourcesCount = DataSource::query()
            ->where('is_active', true)
            ->where('is_required_for_report', true)
            ->count();

        $allPeriods = Period::query()
            ->with(['reportUploads' => fn ($q) => $q->latest()])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderByDesc('sequence')
            ->get();

        $periods = $allPeriods->map(function (Period $period) use ($requiredSourcesCount, $guard, $allPeriods) {
            $closeGuard = $guard->canClose($period);

            // Para periodos base (semanales) contar uploads directos
            // Para compuestos, mostrar 0 / required indicando que no reciben archivos
            $uploadedSourcesCount = 0;
            if ($period->isBase()) {
                $uploadedSourcesCount = $period->reportUploads
                    ->pluck('data_source_id')
                    ->filter()
                    ->unique()
                    ->count();
            }

            // Periodos compuestos: mostrar qué meses / semanas los componen
            $componentLabels = [];
            if (!empty($period->component_period_ids)) {
                $componentLabels = $allPeriods
                    ->whereIn('id', collect($period->component_period_ids)->map(fn ($id) => (int) $id)->all())
                    ->pluck('label')
                    ->values()
                    ->all();
            }

            return [
                'id'                     => $period->id,
                'name'                   => $period->name,
                'code'                   => $period->code,
                'type'                   => $period->type,
                'sequence'               => $period->sequence,
                'label'                  => $period->label,
                'year'                   => $period->year,
                'month'                  => $period->month,
                'start_date'             => optional($period->start_date)->format('Y-m-d'),
                'end_date'               => optional($period->end_date)->format('Y-m-d'),
                'is_closed'              => (bool) $period->is_closed,
                'can_receive_uploads'    => $period->canReceiveUploads(),
                'is_compound'            => $period->isCompound(),
                'component_period_ids'   => $period->component_period_ids ?? [],
                'component_labels'       => $componentLabels,
                'uploaded_sources_count' => $uploadedSourcesCount,
                'required_sources_count' => $period->isBase() ? $requiredSourcesCount : 0,
                'can_close'              => $closeGuard['can_close'],
                'close_issues_count'     => count($closeGuard['issues']),
                'close_issues_preview'   => collect($closeGuard['issues'])->take(2)->values()->all(),
            ];
        })->values();

        // Semanas disponibles para seleccionar al crear un periodo mensual
        $availableWeeks = Period::query()
            ->where('type', 'weekly')
            ->orderBy('year')->orderBy('month')->orderBy('sequence')
            ->get(['id', 'name', 'year', 'month', 'sequence', 'start_date', 'end_date'])
            ->map(fn (Period $w) => [
                'id'         => $w->id,
                'label'      => $w->name,
                'year'       => $w->year,
                'month'      => $w->month,
                'sequence'   => $w->sequence,
                'start_date' => optional($w->start_date)->format('Y-m-d'),
                'end_date'   => optional($w->end_date)->format('Y-m-d'),
            ]);

        return Inertia::render('Periodos/Index', [
            'periods'        => $periods,
            'availableWeeks' => $availableWeeks,
        ]);
    }

    public function store(Request $request, PeriodGenerationService $generator): RedirectResponse
    {
        $validated = $request->validate([
            'type'       => ['required', 'string', 'in:weekly,monthly,bimonthly,quarterly,semiannual,annual'],
            'year'       => ['required', 'integer', 'min:2020', 'max:2100'],
            'month'      => ['required', 'integer', 'min:1', 'max:12'],
            'week_ids'   => ['nullable', 'array'],
            'week_ids.*' => ['integer', 'exists:periods,id'],
        ], [
            'type.required'  => 'El tipo de periodo es obligatorio.',
            'type.in'        => 'El tipo de periodo no es válido.',
            'year.required'  => 'El año es obligatorio.',
            'year.integer'   => 'El año debe ser numérico.',
            'month.required' => 'El mes base es obligatorio.',
            'month.integer'  => 'El mes base debe ser numérico.',
            'month.min'      => 'El mes base no es válido.',
            'month.max'      => 'El mes base no es válido.',
        ]);

        $type    = $validated['type'];
        $year    = (int) $validated['year'];
        $month   = (int) $validated['month'];
        $weekIds = array_map('intval', $validated['week_ids'] ?? []);

        try {
            $periods = $generator->generate($year, $month, $type, $weekIds);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $typeLabels = [
            'weekly'    => 'semanal',
            'monthly'   => 'mensual',
            'bimonthly' => 'bimestral',
            'quarterly' => 'trimestral',
            'semiannual'=> 'semestral',
            'annual'    => 'anual',
        ];
        $typeLabel = $typeLabels[$type] ?? $type;

        return back()->with('success', "Se generaron {$periods->count()} periodo(s) {$typeLabel}(es) correctamente.");
    }

    public function close(Period $period, PeriodCloseGuardService $guard): RedirectResponse
    {
        $result = $guard->canClose($period);

        if (!$result['can_close']) {
            return back()->with('error', 'No se puede cerrar el periodo: ' . implode(' | ', $result['issues']));
        }

        $period->update(['is_closed' => true]);

        return back()->with('success', "El periodo {$period->label} fue cerrado.");
    }

    public function open(Period $period): RedirectResponse
    {
        $period->update(['is_closed' => false]);

        return back()->with('success', "El periodo {$period->label} fue reabierto.");
    }
}
