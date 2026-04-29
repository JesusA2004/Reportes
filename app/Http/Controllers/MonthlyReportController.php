<?php

namespace App\Http\Controllers;

use App\Models\MonthlyEmployeeSummary;
use App\Models\Period;
use App\Services\PeriodConsolidationService;
use App\Services\RadiografiaExportService;
use App\Services\PeriodRadiographyService;
use App\Models\PeriodSummary;
use App\Enums\DataSourceCode;
use App\Models\PeriodRadiographyExport;
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

    public function consolidate(Period $period, PeriodRadiographyService $service): RedirectResponse
    {
        $status = $this->sourceStatus($period);
        if (!empty($status['missing']) || !empty($status['errors'])) {
            $faltantes = implode(', ', array_merge($status['missing'], $status['errors']));
            return back()->with('error', 'No se puede generar la radiografía. Faltan fuentes o análisis procesado: ' . $faltantes . '.');
        }
        $service->generate($period, auth()->id());
        return back()->with('success', 'Radiografía consolidada correctamente.');
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

    public function exportRadiography(Period $period, RadiografiaExportService $service)
    {
        $summary = PeriodSummary::query()->where('period_id', $period->id)->first();
        if (!$summary || $summary->status !== "generated" || $summary->invalidated_at) {
            return back()->with('error', 'No existe un consolidado vigente para exportar la radiografía.');
        }
        $path = $service->export($period);
        $filename = basename($path);

        PeriodRadiographyExport::query()->create([
            'period_summary_id' => $summary->id,
            'export_path' => $path,
            'template_version' => config('app.version'),
            'exported_at' => now(),
            'exported_by' => auth()->id(),
        ]);

        return response()->download($path, $filename);
    }

    public function status(Period $period)
    {
        $summary = PeriodSummary::query()->with('incidents')->where('period_id', $period->id)->first();
        $sources = $this->sourceStatus($period);
        $ready = (bool) ($summary && $summary->status === 'generated' && !$summary->invalidated_at);

        return response()->json([
            'ready' => $ready,
            'status' => $summary?->status ?? 'missing',
            'invalidated_at' => $summary?->invalidated_at,
            'invalidated_reason' => $summary?->invalidated_reason,
            'incidents_count' => (int) ($summary?->incidents?->count() ?? 0),
            'sources_processed' => $sources['processed'],
            'sources_error' => $sources['errors'],
            'sources_missing' => $sources['missing'],
            'can_generate' => !$ready && empty($sources['missing']) && empty($sources['errors']),
            'can_regenerate' => $ready && empty($sources['missing']) && empty($sources['errors']),
            'can_export' => $ready,
        ]);
    }
    private function requiredSourceCodes(): array
    {
        return [
            DataSourceCode::NoiNomina->value,
            DataSourceCode::LendusIngresosCobranza->value,
            DataSourceCode::Gastos->value,
            DataSourceCode::LendusMinistraciones->value,
            DataSourceCode::LendusSaldosCliente->value,
        ];
    }

    private function sourceStatus(Period $period): array
    {
        $uploads = $period->reportUploads()->with('dataSource:id,code,name')->get();
        $required = $this->requiredSourceCodes();

        $processed = [];
        $errors = [];
        $missing = [];

        foreach ($required as $code) {
            $sourceUploads = $uploads->filter(fn($u) => $u->dataSource?->code === $code);
            if ($sourceUploads->isEmpty()) {
                $missing[] = $code;
                continue;
            }
            if ($sourceUploads->contains(fn($u) => (string)($u->status?->value ?? $u->status) === 'processed')) {
                $processed[] = $code;
            } else {
                $errors[] = $code;
            }
        }

        return compact('processed','errors','missing');
    }

}
