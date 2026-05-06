<?php

namespace App\Http\Controllers;

use App\Enums\DataSourceCode;
use App\Models\MonthlyEmployeeSummary;
use App\Models\Branch;
use App\Models\Period;
use App\Models\PeriodBranchSummary;
use App\Models\PeriodRadiographyExport;
use App\Models\PeriodRadiographyRun;
use App\Models\PeriodSummary;
use App\Services\PeriodRadiographyService;
use App\Services\RadiografiaExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MonthlyReportController extends Controller {

    public function index(Request $request): Response {
        $selectedPeriodId = $request->integer('period');
        $periods = Period::query()->orderByDesc('year')->orderByDesc('month')->orderByDesc('sequence')->get(['id','name','code','type','year','month','sequence','start_date','end_date','is_closed'])->map(fn (Period $period) => [
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
        ])->values();
        $summaryRows = collect();
        if ($selectedPeriodId) {
            $summaryRows = MonthlyEmployeeSummary::query()->with(['employee:id,full_name','branch:id,name'])->where('period_id', $selectedPeriodId)->orderByDesc('included_in_report')->orderBy('employee_id')->get()->map(fn (MonthlyEmployeeSummary $summary) => [
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
            ])->values();
        }
        $generatedReports = PeriodSummary::query()
            ->with(['period:id,name,code,type,year,month,sequence,start_date,end_date'])
            ->where('status', 'generated')
            ->latest('generated_at')
            ->get()
            ->map(fn (PeriodSummary $summary) => [
                'id' => $summary->id,
                'name' => 'Radiografía ' . $summary->period?->label,
                'period_id' => $summary->period_id,
                'period' => $summary->period?->label,
                'period_code' => $summary->period?->code,
                'type' => 'Radiografía simple',
                'scope' => 'General',
                'generated_at' => optional($summary->generated_at)->format('d/m/Y H:i'),
                'generated_by' => $summary->generated_by,
                'status' => $summary->invalidated_at ? 'invalidated' : 'generated',
                'excel_url' => route('reportes-mensuales.export-radiography', $summary->period_id),
                'pdf_url' => route('reportes-mensuales.export-radiography-pdf', $summary->period_id),
                'preview_url' => route('reportes-mensuales.preview', $summary->period_id),
            ])->values();

        return Inertia::render('ReportesMensuales/Index', [
            'periods' => $periods,
            'selectedPeriodId' => $selectedPeriodId,
            'summaryRows' => $summaryRows,
            'message' => 'Selecciona un periodo para consolidar y revisar el resumen por empleado.',
            'generatedReports' => $generatedReports,
        ]);
    }

    public function show(Period $period): RedirectResponse {
        return redirect()->route('reportes-mensuales.index', ['period' => $period->id]);
    }

    public function previewPage(Period $period): Response
    {
        $summary = PeriodSummary::query()
            ->with(['branchSummaries', 'incidents'])
            ->where('period_id', $period->id)
            ->where('status', 'generated')
            ->whereNull('invalidated_at')
            ->first();

        $run = PeriodRadiographyRun::query()
            ->where('period_id', $period->id)
            ->whereIn('status', ['success'])
            ->latest('id')
            ->first();

        $hasExcelExport = false;
        $hasPdfExport   = false;

        if ($summary) {
            $excelExport = PeriodRadiographyExport::query()
                ->where('period_summary_id', $summary->id)
                ->where('file_type', 'excel')
                ->latest('id')
                ->first();
            $pdfExport = PeriodRadiographyExport::query()
                ->where('period_summary_id', $summary->id)
                ->where('file_type', 'pdf')
                ->latest('id')
                ->first();
            $hasExcelExport = $excelExport && is_string($excelExport->export_path) && File::exists($excelExport->export_path);
            $hasPdfExport   = $pdfExport && is_string($pdfExport->export_path) && File::exists($pdfExport->export_path);
        }

        $employees = $summary
            ? MonthlyEmployeeSummary::query()
                ->with(['employee:id,full_name', 'branch:id,name'])
                ->where('period_id', $period->id)
                ->orderByDesc('total_payments')
                ->get()
                ->map(fn (MonthlyEmployeeSummary $row) => [
                    'id'               => $row->id,
                    'employee_name'    => $row->employee?->full_name ?? 'Sin empleado',
                    'branch_name'      => $row->branch?->name,
                    'total_payments'   => (float) $row->total_payments,
                    'total_bonuses'    => (float) $row->total_bonuses,
                    'total_discounts'  => (float) $row->total_discounts,
                    'total_expenses'   => (float) $row->total_expenses,
                    'net_amount'       => (float) $row->net_amount,
                    'included_in_report' => (bool) $row->included_in_report,
                    'exclusion_reason' => $row->exclusion_reason,
                ])->values()
            : collect()->values();

        $branchSummaries = $summary
            ? $summary->branchSummaries->map(function (PeriodBranchSummary $bs) {
                $branch = Branch::query()->find($bs->branch_id);
                return [
                    'branch_id'   => $bs->branch_id,
                    'branch_name' => $branch?->name ?? "Sucursal #{$bs->branch_id}",
                    'metrics'     => $bs->metrics ?? [],
                ];
            })->values()
            : collect()->values();

        $incidents = $summary
            ? $summary->incidents->map(fn ($i) => [
                'id'       => $i->id,
                'type'     => $i->type,
                'severity' => $i->severity,
                'message'  => $i->message,
                'context'  => $i->context,
            ])->values()
            : collect()->values();

        $emp = $summary
            ? MonthlyEmployeeSummary::query()
                ->where('period_id', $period->id)
                ->selectRaw('COUNT(*) as total, SUM(total_payments) as pagos, SUM(total_bonuses) as bonos, SUM(total_discounts) as descuentos, SUM(total_expenses) as gastos, SUM(net_amount) as neto')
                ->first()
            : null;

        return Inertia::render('ReportesMensuales/Preview', [
            'period' => [
                'id'         => $period->id,
                'label'      => $period->label,
                'code'       => $period->code,
                'type'       => $period->type,
                'start_date' => optional($period->start_date)->format('Y-m-d'),
                'end_date'   => optional($period->end_date)->format('Y-m-d'),
            ],
            'summary' => $summary ? [
                'id'             => $summary->id,
                'global_metrics' => $summary->global_metrics ?? [],
                'generated_at'   => optional($summary->generated_at)->format('d/m/Y H:i'),
                'version'        => $summary->version,
            ] : null,
            'payrollSummary' => [
                'total_empleados' => (int) ($emp?->total ?? 0),
                'pagos'           => (float) ($emp?->pagos ?? 0),
                'bonos'           => (float) ($emp?->bonos ?? 0),
                'descuentos'      => (float) ($emp?->descuentos ?? 0),
                'gastos'          => (float) ($emp?->gastos ?? 0),
                'neto'            => (float) ($emp?->neto ?? 0),
            ],
            'employees'      => $employees,
            'branchSummaries' => $branchSummaries,
            'incidents'      => $incidents,
            'run'            => $run ? [
                'status'      => $run->status,
                'started_at'  => optional($run->started_at)->format('d/m/Y H:i'),
                'finished_at' => optional($run->finished_at)->format('d/m/Y H:i'),
            ] : null,
            'hasExcelExport' => $hasExcelExport,
            'hasPdfExport'   => $hasPdfExport,
            'excelUrl'       => route('reportes-mensuales.export-radiography', $period->id),
            'pdfUrl'         => route('reportes-mensuales.export-radiography-pdf', $period->id),
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
        $rows = MonthlyEmployeeSummary::query()->with(['employee:id,full_name','branch:id,name'])->where('period_id', $period->id)->orderByDesc('included_in_report')->orderBy('employee_id')->get();
        $filename = sprintf('consolidado_%s.csv', $period->code ?: $period->id);
        return response()->streamDownload(function () use ($rows, $period) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['period_id','period_code','period_label','employee_id','employee_name','branch_id','branch_name','total_payments','total_bonuses','total_discounts','total_expenses','net_amount','has_useful_movement','included_in_report','exclusion_reason']);
            foreach ($rows as $row) {
                fputcsv($handle, [$period->id,$period->code,$period->label,$row->employee_id,$row->employee?->full_name,$row->branch_id,$row->branch?->name,$row->total_payments,$row->total_bonuses,$row->total_discounts,$row->total_expenses,$row->net_amount,$row->has_useful_movement ? 1 : 0,$row->included_in_report ? 1 : 0,$row->exclusion_reason]);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportRadiography(Period $period, RadiografiaExportService $service)
    {
        $latestRun = PeriodRadiographyRun::query()->where('period_id', $period->id)->latest('id')->first();
        if ($latestRun && in_array($latestRun->status, ['queued', 'running'], true)) {
            return back()->with('error', 'La Radiografía todavía se está generando. Intenta nuevamente cuando finalice.');
        }
        $summary = PeriodSummary::query()->where('period_id', $period->id)->first();
        if (!$summary || $summary->status !== 'generated' || $summary->invalidated_at) {
            return back()->with('error', 'No existe un consolidado vigente para exportar la radiografía.');
        }
        $sources = $this->sourceStatus($period);
        if (!empty($sources['missing']) || !empty($sources['errors'])) {
            return back()->with('error', 'No se puede exportar. Faltan fuentes procesadas: ' . implode(', ', array_merge($sources['missing'], $sources['errors'])) . '.');
        }
        $existingExport = PeriodRadiographyExport::query()->where('period_summary_id', $summary->id)->where('file_type', 'excel')->latest('id')->first();
        if ($existingExport && is_string($existingExport->export_path) && File::exists($existingExport->export_path)) {
            return response()->download($existingExport->export_path, basename($existingExport->export_path));
        }
        $path = $service->export($period);
        PeriodRadiographyExport::query()->create([
            'period_summary_id' => $summary->id,
            'export_path' => $path,
            'file_type' => 'excel',
            'template_version' => config('app.version'),
            'metadata' => ['period_id' => $period->id, 'period_label' => $period->label],
            'exported_at' => now(),
            'exported_by' => auth()->id(),
        ]);
        return response()->download($path, basename($path));
    }

    public function exportRadiographyPdf(Period $period, RadiografiaExportService $service)
    {
        $summary = PeriodSummary::query()->where('period_id', $period->id)->first();
        if (!$summary || $summary->status !== 'generated' || $summary->invalidated_at) {
            return back()->with('error', 'No existe un PDF vigente para esta radiografía.');
        }

        $existingExport = PeriodRadiographyExport::query()
            ->where('period_summary_id', $summary->id)
            ->where('file_type', 'pdf')
            ->latest('id')
            ->first();

        if ($existingExport && is_string($existingExport->export_path) && File::exists($existingExport->export_path)) {
            return response()->download($existingExport->export_path, basename($existingExport->export_path));
        }

        $path = $service->exportPdf($period);
        PeriodRadiographyExport::query()->create([
            'period_summary_id' => $summary->id,
            'export_path' => $path,
            'file_type' => 'pdf',
            'template_version' => config('app.version'),
            'metadata' => ['period_id' => $period->id, 'period_label' => $period->label],
            'exported_at' => now(),
            'exported_by' => auth()->id(),
        ]);

        return response()->download($path, basename($path));
    }

    public function status(Period $period) {
        $summary = PeriodSummary::query()->with('incidents')->where('period_id', $period->id)->first();
        $sources = $this->sourceStatus($period);
        $latestRun = PeriodRadiographyRun::query()->where('period_id', $period->id)->latest('id')->first();
        $ready = (bool) ($summary && $summary->status === 'generated' && !$summary->invalidated_at);
        $running = $latestRun && in_array($latestRun->status, ['queued', 'running'], true);
        return response()->json([
            'ready' => $ready,
            'status' => $summary?->status ?? 'missing',
            'invalidated_at' => $summary?->invalidated_at,
            'invalidated_reason' => $summary?->invalidated_reason,
            'incidents_count' => (int) ($summary?->incidents?->count() ?? 0),
            'sources_processed' => $sources['processed'],
            'sources_error' => $sources['errors'],
            'sources_missing' => $sources['missing'],
            'run_status' => $latestRun?->status,
            'run_log' => $latestRun?->log,
            'run_finished_at' => optional($latestRun?->finished_at)->format('d/m/Y H:i'),
            'can_generate' => !$ready && !$running && empty($sources['missing']),
            'can_regenerate' => $ready && !$running && empty($sources['missing']),
            'can_export' => $ready && !$running && empty($sources['missing']) && empty($sources['errors']),
        ]);
    }

    private function requiredSourceCodes(): array {
        return [DataSourceCode::NoiNomina->value, DataSourceCode::LendusIngresosCobranza->value, DataSourceCode::Gastos->value, DataSourceCode::LendusMinistraciones->value, DataSourceCode::LendusSaldosCliente->value];
    }

    private function sourceStatus(Period $period): array {
        $uploads = $period->reportUploads()->with('dataSource:id,code,name')->get();
        $required = $this->requiredSourceCodes();
        $processed = [];
        $errors = [];
        $missing = [];
        foreach ($required as $code) {
            $sourceUploads = $uploads->filter(fn ($upload) => $upload->dataSource?->code === $code);
            if ($sourceUploads->isEmpty()) {
                $missing[] = $code;
                continue;
            }
            if ($sourceUploads->contains(fn ($upload) => (string) ($upload->status?->value ?? $upload->status) === 'processed')) {
                $processed[] = $code;
            } else {
                $errors[] = $code;
            }
        }
        return compact('processed', 'errors', 'missing');
    }

}
