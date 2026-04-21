<?php

namespace App\Http\Controllers;

use App\Models\MonthlyEmployeeSummary;
use App\Models\Period;
use App\Services\PeriodConsolidationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

class MonthlyReportController extends Controller
{
    public function index(Request $request): Response
    {
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

        $summaryRows = collect();

        if ($selectedPeriodId) {
            $summaryRows = MonthlyEmployeeSummary::query()
                ->with([
                    'employee:id,full_name',
                    'branch:id,name',
                ])
                ->where('period_id', $selectedPeriodId)
                ->orderByDesc('included_in_report')
                ->orderBy('employee_id')
                ->get()
                ->map(fn (MonthlyEmployeeSummary $summary) => [
                    'id' => $summary->id,
                    'employee_name' => $summary->employee?->full_name,
                    'branch_name' => $summary->branch?->name,
                    'total_payments' => (float) $summary->total_payments,
                    'total_bonuses' => (float) $summary->total_bonuses,
                    'total_discounts' => (float) $summary->total_discounts,
                    'total_expenses' => (float) $summary->total_expenses,
                    'net_amount' => (float) $summary->net_amount,
                    'has_useful_movement' => (bool) $summary->has_useful_movement,
                    'included_in_report' => (bool) $summary->included_in_report,
                    'exclusion_reason' => $summary->exclusion_reason,
                ])
                ->values();
        }

        return Inertia::render('ReportesMensuales/Index', [
            'periods' => $periods,
            'selectedPeriodId' => $selectedPeriodId,
            'summaryRows' => $summaryRows,
            'message' => 'Selecciona un periodo para consolidar y revisar el resumen por empleado.',
        ]);
    }

    public function show(Period $period): RedirectResponse
    {
        return redirect()->route('reportes-mensuales.index', [
            'period' => $period->id,
        ]);
    }

    public function consolidate(Period $period, PeriodConsolidationService $service): RedirectResponse
    {
        $result = $service->consolidate($period);

        return back()->with(
            'success',
            "Consolidación finalizada. Generados: {$result['created']}, incluidos: {$result['included']}, excluidos: {$result['excluded']}."
        );
    }

    public function exportSummary(Period $period): StreamedResponse
    {
        $rows = MonthlyEmployeeSummary::query()
            ->with([
                'employee:id,full_name',
                'branch:id,name',
            ])
            ->where('period_id', $period->id)
            ->orderByDesc('included_in_report')
            ->orderBy('employee_id')
            ->get();

        $filename = sprintf('consolidado_%s.csv', $period->code ?: $period->id);

        return response()->streamDownload(function () use ($rows, $period) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'period_id',
                'period_code',
                'period_label',
                'employee_id',
                'employee_name',
                'branch_id',
                'branch_name',
                'total_payments',
                'total_bonuses',
                'total_discounts',
                'total_expenses',
                'net_amount',
                'has_useful_movement',
                'included_in_report',
                'exclusion_reason',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $period->id,
                    $period->code,
                    $period->label,
                    $row->employee_id,
                    $row->employee?->full_name,
                    $row->branch_id,
                    $row->branch?->name,
                    $row->total_payments,
                    $row->total_bonuses,
                    $row->total_discounts,
                    $row->total_expenses,
                    $row->net_amount,
                    $row->has_useful_movement ? 1 : 0,
                    $row->included_in_report ? 1 : 0,
                    $row->exclusion_reason,
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportRadiography(Period $period): StreamedResponse
    {
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
