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
            ->count();

        $periods = Period::query()
            ->with([
                'reportUploads' => fn ($query) => $query->latest(),
            ])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderByDesc('sequence')
            ->get()
            ->map(function (Period $period) use ($requiredSourcesCount, $guard) {
                $uploads = $period->reportUploads;

                $uploadedSourcesCount = $uploads
                    ->pluck('data_source_id')
                    ->filter()
                    ->unique()
                    ->count();

                $closeGuard = $guard->canClose($period);

                return [
                    'id' => $period->id,
                    'name' => $period->name,
                    'code' => $period->code,
                    'type' => $period->type,
                    'sequence' => $period->sequence,
                    'label' => $period->label,
                    'year' => $period->year,
                    'month' => $period->month,
                    'start_date' => optional($period->start_date)->format('Y-m-d'),
                    'end_date' => optional($period->end_date)->format('Y-m-d'),
                    'is_closed' => (bool) $period->is_closed,
                    'uploaded_sources_count' => $uploadedSourcesCount,
                    'required_sources_count' => $requiredSourcesCount,
                    'can_close' => $closeGuard['can_close'],
                    'close_issues_count' => count($closeGuard['issues']),
                    'close_issues_preview' => collect($closeGuard['issues'])->take(2)->values(),
                ];
            })
            ->values();

        return Inertia::render('Periodos/Index', [
            'periods' => $periods,
        ]);
    }

    public function store(Request $request, PeriodGenerationService $generator): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:weekly,bimonthly,quarterly,semiannual,annual'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ], [
            'type.required' => 'El tipo de periodo es obligatorio.',
            'type.in' => 'El tipo de periodo no es válido.',
            'year.required' => 'El año es obligatorio.',
            'year.integer' => 'El año debe ser numérico.',
            'month.required' => 'El mes base es obligatorio.',
            'month.integer' => 'El mes base debe ser numérico.',
            'month.min' => 'El mes base no es válido.',
            'month.max' => 'El mes base no es válido.',
        ]);

        $type = $validated['type'];
        $year = (int) $validated['year'];
        $month = (int) $validated['month'];

        $periods = $generator->generate($year, $month, $type);

        return back()->with('success', "Se generaron {$periods->count()} periodo(s) de tipo {$type} correctamente.");
    }

    public function close(Period $period, PeriodCloseGuardService $guard): RedirectResponse
    {
        $result = $guard->canClose($period);

        if (!$result['can_close']) {
            return back()->with('error', 'No se puede cerrar el periodo porque tiene incidencias críticas: ' . implode(' | ', $result['issues']));
        }

        $period->update([
            'is_closed' => true,
        ]);

        return back()->with('success', "El periodo {$period->label} fue cerrado.");
    }

    public function open(Period $period): RedirectResponse
    {
        $period->update([
            'is_closed' => false,
        ]);

        return back()->with('success', "El periodo {$period->label} fue reabierto.");
    }
}
