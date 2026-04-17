<?php

namespace App\Http\Controllers;

use App\Models\Period;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MonthlyReportController extends Controller {

    public function index(Request $request): Response {
        $selectedPeriodId = $request->integer('period');

        $periods = Period::query()
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderByDesc('sequence')
            ->get([
                'id',
                'name',
                'code',
                'type',
                'year',
                'month',
                'sequence',
                'start_date',
                'end_date',
                'is_closed',
            ])
            ->map(fn (Period $period) => [
                'id' => $period->id,
                'name' => $period->name,
                'label' => $period->label,
                'code' => $period->code,
                'type' => $period->type,
                'year' => $period->year,
                'month' => $period->month,
                'sequence' => $period->sequence,
                'start_date' => optional($period->start_date)->format('Y-m-d'),
                'end_date' => optional($period->end_date)->format('Y-m-d'),
                'is_closed' => (bool) $period->is_closed,
            ])
            ->values();

        return Inertia::render('ReportesMensuales/Index', [
            'periods' => $periods,
            'selectedPeriodId' => $selectedPeriodId,
            'message' => 'El consolidado por periodo está en construcción. Usa esta vista para seleccionar periodo y preparar la siguiente fase.',
        ]);
    }

    public function show(Period $period): RedirectResponse {
        return redirect()->route('reportes-mensuales.index', [
            'period' => $period->id,
        ]);
    }

    public function consolidate(Period $period): RedirectResponse {
        return back()->with('warning', "La consolidación automática para {$period->label} aún no está implementada.");
    }

}
