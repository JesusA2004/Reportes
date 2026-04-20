<?php

namespace App\Http\Controllers;

use App\Models\Period;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

    public function exportRadiography(Period $period): StreamedResponse {
        $uploads = $period->reportUploads()
            ->with('dataSource:id,code,name')
            ->with(['processRuns' => fn ($query) => $query->latest()])
            ->get();

        $filename = sprintf('radiografia_%s.csv', $period->code ?: $period->id);

        return response()->streamDownload(function () use ($period, $uploads) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'period_id',
                'period_code',
                'period_label',
                'source_code',
                'source_name',
                'file_name',
                'upload_status',
                'analysis_status',
                'rows_read',
                'rows_inserted',
                'rows_with_errors',
                'analysis_finished_at',
            ]);

            foreach ($uploads as $upload) {
                $run = $upload->processRuns->first();
                fputcsv($handle, [
                    $period->id,
                    $period->code,
                    $period->label,
                    $upload->dataSource?->code,
                    $upload->dataSource?->name,
                    $upload->original_name,
                    $upload->status?->value ?? $upload->status,
                    $run?->status?->value ?? $run?->status,
                    $run?->rows_read ?? 0,
                    $run?->rows_inserted ?? 0,
                    $run?->rows_with_errors ?? 0,
                    optional($run?->finished_at)->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

}
