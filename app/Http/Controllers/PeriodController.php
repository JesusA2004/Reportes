<?php

namespace App\Http\Controllers;

use App\Models\DataSource;
use App\Models\Period;
use App\Services\WeeklyPeriodGeneratorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PeriodController extends Controller {

    public function index(): Response {
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
            ->map(function (Period $period) use ($requiredSourcesCount) {
                $uploads = $period->reportUploads;
                $processedCount = $uploads->where('status', 'processed')->count();
                $pendingCount = $uploads->whereIn('status', ['pending', 'processing'])->count();
                $failedCount = $uploads->where('status', 'failed')->count();
                $uploadedSourcesCount = $uploads
                    ->pluck('data_source_id')
                    ->filter()
                    ->unique()
                    ->count();

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
                    'missing_sources_count' => max($requiredSourcesCount - $uploadedSourcesCount, 0),
                    'processed_count' => $processedCount,
                    'pending_count' => $pendingCount,
                    'failed_count' => $failedCount,
                    'updated_at' => optional($period->updated_at)->format('d/m/Y H:i'),
                ];
            })
            ->values();

        return Inertia::render('Periodos/Index', [
            'periods' => $periods,
        ]);
    }

    public function store(Request $request, WeeklyPeriodGeneratorService $weeklyGenerator): RedirectResponse {
        $validated = $request->validate([
            'type' => ['nullable', 'string', 'in:weekly,bimonthly,quarterly,semiannual,annual'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ], [
            'type.in' => 'El tipo de periodo no es válido.',
            'year.required' => 'El año es obligatorio.',
            'year.integer' => 'El año debe ser numérico.',
            'month.required' => 'El mes es obligatorio.',
            'month.integer' => 'El mes debe ser numérico.',
            'month.min' => 'El mes no es válido.',
            'month.max' => 'El mes no es válido.',
        ]);

        $year = (int) $validated['year'];
        $month = (int) $validated['month'];
        $type = $validated['type'] ?? 'weekly';

        if ($type === 'weekly') {
            $createdPeriods = $weeklyGenerator->generateForMonth($year, $month);

            return back()->with('success', "Se generaron {$createdPeriods->count()} semanas para {$year}-".str_pad((string) $month, 2, '0', STR_PAD_LEFT).'.');
        }

        return back()->withErrors([
            'type' => 'Por ahora la creación manual soporta periodos semanales. Usa weekly.',
        ]);
    }

    public function close(Period $period): RedirectResponse {
        $period->update([
            'is_closed' => true,
        ]);

        return back()->with('success', "El periodo {$period->label} fue cerrado.");
    }

    public function open(Period $period): RedirectResponse {
        $period->update([
            'is_closed' => false,
        ]);

        return back()->with('success', "El periodo {$period->label} fue reabierto.");
    }

}
