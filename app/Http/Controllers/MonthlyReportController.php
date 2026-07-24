<?php

namespace App\Http\Controllers;

use App\Enums\DataSourceCode;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\MonthlyEmployeeSummary;
use App\Models\Period;
use App\Models\PeriodRadiographyExport;
use App\Models\PeriodBranchSummary;
use App\Models\PeriodRadiographyRun;
use App\Models\PeriodSummary;
use App\Models\ReportUpload;
use App\Services\PeriodRadiographyService;
use App\Services\RadiografiaExportService;
use App\Services\Radiography\RadiographySnapshotBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MonthlyReportController extends Controller {

    /** Las 13 sucursales operativas — fuente de verdad para el selector UI y validación. */
    private const OPERATIVE_BRANCH_NAMES = [
        'ATLACOMULCO', 'ATLIXCO', 'CORDOBA', 'CUERNAVACA', 'HUAMANTLA',
        'IXTLAHUACA', 'MIACATLAN', 'ORIZABA', 'SAN JUAN DEL RÍO',
        'SAN LUIS POTOSI', 'TENANGO DEL VALLE', 'TLAXCALA', 'TULA',
    ];

    public function index(Request $request): Response {
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
            'message' => 'Consulta, previsualiza y descarga los reportes ya generados.',
            'generatedReports' => $generatedReports,
        ]);
    }

    public function show(Period $period): RedirectResponse {
        return redirect()->route('reportes-mensuales.index', ['period' => $period->id]);
    }

    public function previewPage(Period $period, RadiographySnapshotBuilder $snapshotBuilder): Response
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
        $snapshot       = null;

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

            // Build full snapshot (single source of truth)
            $snapshot = $snapshotBuilder->build($period, $summary);
        }

        // Operative branches: hardcoded constant — never shows routes or non-op branches
        $operativeBranches = Branch::query()
            ->whereIn('name', self::OPERATIVE_BRANCH_NAMES)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])
            ->values();

        // Gestores: from snapshot employees_gestores (has per-gestor metrics)
        $employees = collect();
        if ($snapshot) {
            $gestores = $snapshot['sections']['employees_gestores'] ?? [];
            // Build list: prefer employees with actual colocacion or recuperacion data
            $employees = collect($gestores)
                ->sortBy('name')
                ->map(fn ($g) => [
                    'id'           => 0,            // will be resolved below
                    'name'         => $g['name'],
                    'branch'       => $g['branch'] ?? '',
                    'recuperacion' => (float)($g['recuperacion'] ?? 0),
                    'colocacion'   => (float)($g['colocacion'] ?? 0),
                ])
                ->values();

            // Resolve DB employee IDs (for download endpoint)
            $allEmpIds = Employee::query()
                ->orderBy('full_name')
                ->get(['id', 'full_name'])
                ->keyBy(fn ($e) => strtoupper(trim($e->full_name)));

            $employees = $employees->map(function ($e) use ($allEmpIds) {
                $key = strtoupper(trim($e['name']));
                $dbEmp = $allEmpIds->get($key);
                return [
                    'id'     => $dbEmp?->id ?? 0,
                    'name'   => $e['name'],
                    'branch' => $e['branch'],
                ];
            })->filter(fn ($e) => $e['id'] > 0)->values();

            if ($employees->isEmpty()) {
                $employees = Employee::query()->orderBy('full_name')
                    ->get(['id', 'full_name'])
                    ->map(fn ($e) => ['id' => $e->id, 'name' => $e->full_name, 'branch' => ''])
                    ->values();
            }
        }

        // All available periods for compare selectors (with snapshot flag for comparativo protection)
        $periodsWithSnap = PeriodSummary::where('status', 'generated')
            ->pluck('period_id')
            ->flip();
        $allPeriods = Period::query()
            ->orderByDesc('year')->orderByDesc('month')->orderByDesc('sequence')
            ->get(['id', 'name', 'code', 'type', 'year', 'month'])
            ->map(fn ($p) => [
                'id'           => $p->id,
                'label'        => $p->label,
                'code'         => $p->code,
                'type'         => $p->type,
                'has_snapshot' => $periodsWithSnap->has($p->id),
            ])
            ->values();

        return Inertia::render('ReportesMensuales/Preview', [
            'period' => [
                'id'         => $period->id,
                'label'      => $period->label,
                'code'       => $period->code,
                'type'       => $period->type,
                'start_date' => optional($period->start_date)->format('Y-m-d'),
                'end_date'   => optional($period->end_date)->format('Y-m-d'),
            ],
            'snapshot'       => $snapshot,
            'run'            => $run ? [
                'status'      => $run->status,
                'started_at'  => optional($run->started_at)->format('d/m/Y H:i'),
                'finished_at' => optional($run->finished_at)->format('d/m/Y H:i'),
            ] : null,
            'hasExcelExport' => $hasExcelExport,
            'hasPdfExport'   => $hasPdfExport,
            'excelUrl'       => route('reportes-mensuales.export-radiography', $period->id),
            'pdfUrl'         => route('reportes-mensuales.export-radiography-pdf', $period->id),
            'branches'       => $operativeBranches,
            'employees'      => $employees,
            'allPeriods'     => $allPeriods,
            'filteredExcelBaseUrl' => route('reportes-mensuales.export-filtered-radiography', $period->id),
            'filteredPdfBaseUrl'   => route('reportes-mensuales.export-filtered-radiography-pdf', $period->id),
            'filteredDataUrl'      => route('reportes-mensuales.filtered-preview-data', $period->id),
            'updateSaldoInicialUrl' => route('reportes-mensuales.update-saldo-inicial', $period->id),
        ]);
    }

    public function consolidate(Period $period, PeriodRadiographyService $service): RedirectResponse
    {
        $status = $this->sourceStatus($period);
        $allMissing = array_merge($status['missing'], $status['errors']);
        if (!empty($allMissing)) {
            if (in_array(DataSourceCode::GastosLendusExcel->value, $allMissing, true)) {
                return back()->with('error', 'Falta cargar el Excel complementario de gastos Lendus. Este archivo es necesario para identificar la sucursal que recibe en préstamos intersucursales.');
            }
            return back()->with('error', 'No se puede generar la radiografía. Faltan fuentes o análisis procesado: ' . implode(', ', $allMissing) . '.');
        }
        $service->generate($period, auth()->id());
        return back()->with('success', 'Radiografía consolidada correctamente.');
    }

    /**
     * Captura/actualiza el saldo inicial en caja del periodo — único insumo del cálculo de
     * EBITDA que no viene de ninguna fuente importada. Sin este dato el sistema no debe asumir
     * $0 en silencio; este endpoint permite capturarlo desde el preview de la radiografía.
     */
    public function updateSaldoInicial(Period $period, Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'saldo_inicial_caja' => ['required', 'numeric'],
        ]);

        $period->update(['saldo_inicial_caja' => $validated['saldo_inicial_caja']]);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'saldo_inicial_caja' => (float) $period->saldo_inicial_caja]);
        }

        return back()->with('success', 'Saldo inicial en caja actualizado.');
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
        // Check summary FIRST — a pending old job must NOT block an already-generated report
        $summary = PeriodSummary::query()
            ->where('period_id', $period->id)
            ->where('status', 'generated')
            ->whereNull('invalidated_at')
            ->latest('id')
            ->first();

        if (!$summary) {
            return response('No existe un consolidado vigente para exportar la radiografía.', 409);
        }

        // Log if there is a stale queued/running job (informational only)
        $latestRun = PeriodRadiographyRun::query()->where('period_id', $period->id)->latest('id')->first();
        if ($latestRun && in_array($latestRun->status, ['queued', 'running'], true)) {
            \Illuminate\Support\Facades\Log::warning('exportRadiography: stale job detected but summary exists, proceeding.', [
                'period_id' => $period->id,
                'run_id'    => $latestRun->id,
                'run_status'=> $latestRun->status,
            ]);
        }

        // Build snapshot to check if expense data actually exists before trusting sourceStatus
        $snapshot = app(RadiographySnapshotBuilder::class)->build($period, $summary);

        $hasExpensesInSnapshot =
            (float) data_get($snapshot, 'summary.expenses_total', 0) > 0
            || (float) data_get($snapshot, 'sections.expenses_detail.total', 0) > 0
            || (float) data_get($snapshot, 'sections.expenses_matrix.grand_total', 0) > 0;

        $sources = $this->sourceStatus($period);

        Log::info('Excel export source validation', [
            'period_id'             => $period->id,
            'period_label'          => $period->label,
            'missing'               => $sources['missing'] ?? [],
            'errors'                => $sources['errors'] ?? [],
            'has_expenses_snapshot' => $hasExpensesInSnapshot,
            'expenses_total'        => data_get($snapshot, 'summary.expenses_total'),
            'expenses_detail_total' => data_get($snapshot, 'sections.expenses_detail.total'),
            'expenses_matrix_total' => data_get($snapshot, 'sections.expenses_matrix.grand_total'),
        ]);

        $missing = collect($sources['missing'] ?? []);
        $errors  = collect($sources['errors'] ?? []);

        // gastos_lendus_excel is unconditionally required — never bypass, even when expenses exist.
        // Without it, P. INTERSUC. shows "No identificada" for every intersucursal row.
        if ($missing->contains(DataSourceCode::GastosLendusExcel->value)
            || $errors->contains(DataSourceCode::GastosLendusExcel->value)) {
            return response(
                'Falta cargar el Excel complementario de gastos Lendus. Este archivo es necesario para identificar la sucursal que recibe en préstamos intersucursales.',
                409
            );
        }

        // Other gastos sources may be bypassed if expense data already exists in the snapshot.
        if ($hasExpensesInSnapshot) {
            $gastosCodes = [DataSourceCode::Gastos->value, 'GASTOS'];
            $missing = $missing->reject(fn ($code) => in_array($code, $gastosCodes, true));
            $errors  = $errors->reject(fn ($code) => in_array($code, $gastosCodes, true));
        }

        if ($missing->isNotEmpty() || $errors->isNotEmpty()) {
            return response(
                'No se puede exportar. Faltan fuentes procesadas: ' . $missing->merge($errors)->implode(', ') . '.',
                409
            );
        }

        try {
            $path = $service->export($period);
        } catch (\Throwable $e) {
            report($e);
            return response('No se pudo generar el Excel: ' . $e->getMessage(), 500);
        }

        if (!file_exists($path) || filesize($path) === 0) {
            return response('El archivo Excel generado está vacío o no existe.', 500);
        }

        PeriodRadiographyExport::query()->create([
            'period_summary_id' => $summary->id,
            'export_path'       => $path,
            'file_type'         => 'excel',
            'template_version'  => config('app.version'),
            'metadata'          => ['period_id' => $period->id, 'period_label' => $period->label],
            'exported_at'       => now(),
            'exported_by'       => auth()->id(),
        ]);

        return response()->download($path, basename($path), [
            'Content-Type'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
        ]);
    }

    public function exportRadiographyPdf(Period $period, RadiografiaExportService $service)
    {
        $summary = PeriodSummary::query()
            ->where('period_id', $period->id)
            ->where('status', 'generated')
            ->whereNull('invalidated_at')
            ->latest('id')
            ->first();

        if (!$summary) {
            return response('No existe un consolidado vigente para exportar el PDF.', 409);
        }

        try {
            $path = $service->exportPdf($period);
        } catch (\Throwable $e) {
            report($e);
            return response('No se pudo generar el PDF: ' . $e->getMessage(), 500);
        }

        if (!file_exists($path) || filesize($path) === 0) {
            return response('El archivo PDF generado está vacío o no existe.', 500);
        }

        PeriodRadiographyExport::query()->create([
            'period_summary_id' => $summary->id,
            'export_path'       => $path,
            'file_type'         => 'pdf',
            'template_version'  => config('app.version'),
            'metadata'          => ['period_id' => $period->id, 'period_label' => $period->label],
            'exported_at'       => now(),
            'exported_by'       => auth()->id(),
        ]);

        return response()->download($path, basename($path), [
            'Content-Type'  => 'application/pdf',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
        ]);
    }

    public function filteredPreviewData(Period $period, Request $request): JsonResponse
    {
        $summary = PeriodSummary::query()
            ->where('period_id', $period->id)
            ->where('status', 'generated')
            ->whereNull('invalidated_at')
            ->first();

        if (!$summary) {
            return response()->json(['error' => 'Sin radiografía generada para este periodo.'], 404);
        }

        $snapshot   = app(RadiographySnapshotBuilder::class)->build($period, $summary);
        $scope      = $request->input('scope', 'general');
        $branchId   = (int) $request->input('branch_id', 0);
        $employeeId = (int) $request->input('employee_id', 0);

        if ($scope === 'branch') {
            if (!$branchId) {
                return response()->json(['error' => 'Selecciona una sucursal.'], 422);
            }
            $branch = Branch::find($branchId);
            if (!$branch || !in_array($branch->name, self::OPERATIVE_BRANCH_NAMES, true)) {
                return response()->json(['error' => 'Selecciona una sucursal operativa válida.'], 422);
            }
            $brName  = strtoupper(trim($branch->name));
            $brCalc  = collect($snapshot['branch_radiography']['branches'] ?? [])
                ->first(fn ($b) => strtoupper(trim($b['sucursal'])) === $brName);
            if (!$brCalc) {
                return response()->json(['error' => "Sin datos de radiografía para {$branch->name} en este periodo."], 404);
            }
            return response()->json([
                'scope'  => 'branch',
                'label'  => $branch->name,
                'data'   => [
                    'recuperacion' => (float)($brCalc['recuperacion_total'] ?? 0),
                    'colocacion'   => (float)($brCalc['colocacion'] ?? 0),
                    'cartera'      => (float)($brCalc['valor_cartera'] ?? 0),
                    'mora_total'   => (float)($brCalc['mora_0_30'] ?? 0) + (float)($brCalc['mora_31_60'] ?? 0)
                        + (float)($brCalc['mora_61_90'] ?? 0) + (float)($brCalc['mora_91_120'] ?? 0)
                        + (float)($brCalc['mora_120_plus'] ?? 0),
                    'mora_pct'     => (float)($brCalc['valor_cartera'] ?? 0) > 0
                        ? round((((float)($brCalc['mora_0_30'] ?? 0) + (float)($brCalc['mora_31_60'] ?? 0)
                            + (float)($brCalc['mora_61_90'] ?? 0) + (float)($brCalc['mora_91_120'] ?? 0)
                            + (float)($brCalc['mora_120_plus'] ?? 0)) / (float)($brCalc['valor_cartera'])) * 100, 2)
                        : 0,
                    'gastos'       => (float)($brCalc['gastos_operativos'] ?? 0),
                    'nomina'       => (float)($brCalc['nomina_total'] ?? 0) + (float)($brCalc['comisiones'] ?? 0)
                        + (float)($brCalc['bonos'] ?? 0),
                ],
            ]);
        }

        if ($scope === 'employee') {
            if (!$employeeId) {
                return response()->json(['error' => 'Selecciona un gestor.'], 422);
            }
            $employee = Employee::find($employeeId);
            if (!$employee) {
                return response()->json(['error' => 'Gestor no encontrado.'], 404);
            }
            $empNorm  = strtoupper(trim($employee->full_name ?? ''));
            $gestores = $snapshot['sections']['employees_gestores'] ?? [];
            $gestRow  = null;
            foreach ($gestores as $g) {
                if (strtoupper(trim($g['name'] ?? '')) === $empNorm) {
                    $gestRow = $g;
                    break;
                }
            }
            // Partial match fallback
            if (!$gestRow) {
                foreach ($gestores as $g) {
                    $norm = strtoupper(trim($g['name'] ?? ''));
                    if ($norm && (str_contains($norm, $empNorm) || str_contains($empNorm, $norm))) {
                        $gestRow = $g;
                        break;
                    }
                }
            }
            if (!$gestRow) {
                return response()->json([
                    'scope' => 'employee',
                    'label' => $employee->full_name,
                    'error' => 'Sin registros para este gestor en el periodo.',
                    'data'  => null,
                ]);
            }
            return response()->json([
                'scope'  => 'employee',
                'label'  => $employee->full_name,
                'data'   => [
                    'branch'       => $gestRow['branch']       ?? '',
                    'route'        => $gestRow['route']        ?? '',
                    'recuperacion' => (float)($gestRow['recuperacion']  ?? 0),
                    'colocacion'   => (float)($gestRow['colocacion']    ?? 0),
                    'operaciones'  => (int)($gestRow['operaciones']     ?? 0),
                    'cartera'      => (float)($gestRow['cartera']       ?? 0),
                    'mora_total'   => (float)($gestRow['vencida']       ?? 0),
                    'mora_pct'     => (float)($gestRow['mora']          ?? 0),
                    'pagos'        => (float)($gestRow['pagos']         ?? 0),
                    'bonos'        => (float)($gestRow['bonos']         ?? 0),
                    'descuentos'   => (float)($gestRow['descuentos']    ?? 0),
                    'neto'         => (float)($gestRow['neto']          ?? 0),
                    'gastos'       => (float)($gestRow['gastos']        ?? 0),
                ],
            ]);
        }

        return response()->json(['error' => 'Scope no válido. Usa branch o employee.'], 422);
    }

    public function exportFilteredRadiography(Period $period, Request $request, RadiografiaExportService $service)
    {
        $summary = PeriodSummary::query()
            ->where('period_id', $period->id)
            ->where('status', 'generated')
            ->whereNull('invalidated_at')
            ->latest('id')
            ->first();

        if (!$summary) {
            return response('No existe un consolidado vigente para exportar la radiografía.', 409);
        }

        $config = $request->only([
            'scope', 'report_type', 'branch_id', 'employee_id',
            'compare_period_id', 'extra_employee_expense_amount', 'extra_employee_expense_notes',
        ]);

        // Validate branch is operative
        if (($config['scope'] ?? '') === 'branch') {
            $branchId = (int)($config['branch_id'] ?? 0);
            if (!$branchId) {
                return response('Selecciona una sucursal.', 422);
            }
            $branch = Branch::find($branchId);
            if (!$branch || !in_array($branch->name, self::OPERATIVE_BRANCH_NAMES, true)) {
                return response('Selecciona una sucursal operativa válida.', 422);
            }
        }

        if (($config['scope'] ?? '') === 'employee' && !(int)($config['employee_id'] ?? 0)) {
            return response('Selecciona un gestor.', 422);
        }

        try {
            $path = $service->exportWithConfig($period, $config);
        } catch (\Throwable $e) {
            report($e);
            return response('No se pudo generar el Excel filtrado: ' . $e->getMessage(), 500);
        }

        if (!file_exists($path) || filesize($path) === 0) {
            return response('El archivo Excel generado está vacío o no existe.', 500);
        }

        return response()->download($path, basename($path), [
            'Content-Type'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
        ]);
    }

    public function exportFilteredRadiographyPdf(Period $period, Request $request, RadiografiaExportService $service)
    {
        $summary = PeriodSummary::query()
            ->where('period_id', $period->id)
            ->where('status', 'generated')
            ->whereNull('invalidated_at')
            ->latest('id')
            ->first();

        if (!$summary) {
            return response('No existe un consolidado vigente para exportar el PDF.', 409);
        }

        $config = $request->only([
            'scope', 'report_type', 'branch_id', 'employee_id',
            'compare_period_id', 'extra_employee_expense_amount', 'extra_employee_expense_notes',
        ]);

        try {
            $path = $service->exportPdfWithConfig($period, $config);
        } catch (\Throwable $e) {
            report($e);
            return response('No se pudo generar el PDF filtrado: ' . $e->getMessage(), 500);
        }

        if (!file_exists($path) || filesize($path) === 0) {
            return response('El archivo PDF generado está vacío o no existe.', 500);
        }

        return response()->download($path, basename($path), [
            'Content-Type'  => 'application/pdf',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
        ]);
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
        return [
            DataSourceCode::NoiNomina->value,
            DataSourceCode::LendusIngresosCobranza->value,
            DataSourceCode::Gastos->value,
            DataSourceCode::LendusMinistraciones->value,
            DataSourceCode::LendusSaldosCliente->value,
            DataSourceCode::GastosLendusExcel->value,
        ];
    }

    // Códigos que satisfacen el requisito 'gastos' (incluyendo fuentes desagregadas)
    private function gastosEquivalentCodes(): array {
        return [
            DataSourceCode::Gastos->value,
            DataSourceCode::GastosLendus->value,
            DataSourceCode::GastosErp->value,
        ];
    }

    private function sourceStatus(Period $period): array {
        // Incluir uploads del periodo mensual Y de todas sus semanas componentes
        $allPeriods  = Period::all();
        $weeklyIds   = $period->resolveBaseWeeklyIds($allPeriods);
        $allIds      = array_unique(array_merge([$period->id], $weeklyIds));

        $uploads  = ReportUpload::query()
            ->whereIn('period_id', $allIds)
            ->with('dataSource:id,code,name')
            ->get();

        $required  = $this->requiredSourceCodes();
        $gastosCodes = $this->gastosEquivalentCodes();

        $processed = [];
        $errors    = [];
        $missing   = [];

        foreach ($required as $code) {
            // Para gastos aceptar cualquier variante procesada
            $codesToCheck = $code === DataSourceCode::Gastos->value ? $gastosCodes : [$code];

            $sourceUploads = $uploads->filter(
                fn ($upload) => in_array($upload->dataSource?->code, $codesToCheck, true)
            );

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
