<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReportUploadRequest;
use App\Jobs\GenerateRadiographyJob;
use App\Jobs\UpdatePeriodDatabaseJob;
use App\Models\Branch;
use App\Models\DataSource;
use App\Models\Employee;
use App\Models\Period;
use App\Models\PeriodDatabaseUpdateRun;
use App\Models\PeriodIncident;
use App\Models\PeriodRadiographyRun;
use App\Models\MonthlyEmployeeSummary;
use App\Models\PeriodSummary;
use App\Models\ReportUpload;
use App\Services\ReportAnalysisService;
use App\Services\ReportUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ReportUploadController extends Controller {

    public function index(): Response {
        $sources = DataSource::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'description']);
        $requiredSourcesCount = $sources->count();
        $summariesByPeriod = PeriodSummary::query()->with('incidents')->get()->keyBy('period_id');
        $runsByPeriod = PeriodRadiographyRun::query()->orderByDesc('id')->get()->unique('period_id')->keyBy('period_id');
        $dbRunsByPeriod = PeriodDatabaseUpdateRun::query()->orderByDesc('id')->get()->unique('period_id')->keyBy('period_id');

        $periodModels = Period::query()->orderByDesc('year')->orderByDesc('month')->orderByDesc('sequence')->get();
        $weeklyPeriods = $periodModels->where('type', 'weekly')->values();

        $periods = $periodModels->map(function (Period $period) use ($weeklyPeriods, $sources, $requiredSourcesCount, $summariesByPeriod, $runsByPeriod, $dbRunsByPeriod) {
            $coveredWeeks = $this->resolveCoveredWeeks($period, $weeklyPeriods);
            $uploads = $this->resolveUploadsForPeriod($coveredWeeks);
            $uploadedSourceCodes = $uploads->pluck('dataSource.code')->filter()->unique()->values();
            $missingSources = $sources->whereNotIn('code', $uploadedSourceCodes)->pluck('name')->values();
            $summary = $summariesByPeriod->get($period->id);
            $run = $runsByPeriod->get($period->id);
            $dbRun = $dbRunsByPeriod->get($period->id);

            return [
                'id' => $period->id,
                'code' => $period->code,
                'label' => $period->label,
                'type' => $period->type,
                'year' => $period->year,
                'month' => $period->month,
                'is_closed' => (bool) $period->is_closed,
                'can_receive_uploads' => $period->type === 'weekly',
                'is_derived' => $period->type !== 'weekly',
                'start_date' => optional($period->start_date)->format('Y-m-d'),
                'end_date' => optional($period->end_date)->format('Y-m-d'),
                'updated_at' => optional($uploads->sortByDesc('created_at')->first()?->created_at)->format('d/m/Y H:i'),
                'uploaded_sources_count' => $uploadedSourceCodes->count(),
                'required_sources_count' => $requiredSourcesCount,
                'missing_sources_count' => $missingSources->count(),
                'processed_count' => $uploads->where('status', 'processed')->count(),
                'pending_count' => $uploads->whereIn('status', ['pending', 'processing'])->count(),
                'failed_count' => $uploads->where('status', 'failed')->count(),
                'missing_sources' => $missingSources,
                'report_final_available' => $missingSources->count() === 0 && $requiredSourcesCount > 0,
                'radiography_status' => $summary?->status ?? 'missing',
                'radiography_invalidated' => (bool) $summary?->invalidated_at,
                'radiography_run_status' => $run?->status,
                'radiography_run_log' => $run?->log,
                'radiography_run_finished_at' => optional($run?->finished_at)->format('d/m/Y H:i'),
                ...$this->resolveWorkflowState($uploads, $summary, $run, $period->type !== 'weekly', $dbRun),
                'available_week_options' => $period->type === 'weekly' ? $weeklyPeriods->where('year', $period->year)->where('month', $period->month)->sortBy('sequence')->map(fn ($week) => [
                    'id' => $week->id,
                    'label' => $week->label,
                    'sequence' => $week->sequence,
                    'start_date' => optional($week->start_date)->format('Y-m-d'),
                    'end_date' => optional($week->end_date)->format('Y-m-d'),
                ])->values() : collect(),
                'source_periods' => $coveredWeeks->map(fn ($week) => [
                    'id' => $week->id,
                    'label' => $week->label,
                    'start_date' => optional($week->start_date)->format('Y-m-d'),
                    'end_date' => optional($week->end_date)->format('Y-m-d'),
                    'uploaded_sources_count' => $this->resolveUploadsForPeriod(collect([$week]))->pluck('dataSource.code')->filter()->unique()->count(),
                    'required_sources_count' => $requiredSourcesCount,
                    'complete' => $this->resolveUploadsForPeriod(collect([$week]))->filter(fn ($upload) => (string) ($upload->status?->value ?? $upload->status) === 'processed')->pluck('dataSource.code')->filter()->unique()->count() >= $requiredSourcesCount,
                ])->values(),
            ];
        })->values();

        $groupedUploads = $periodModels->map(function (Period $period) use ($weeklyPeriods, $sources, $requiredSourcesCount, $summariesByPeriod, $runsByPeriod, $dbRunsByPeriod) {
            $coveredWeeks = $this->resolveCoveredWeeks($period, $weeklyPeriods);
            $uploads = $this->resolveUploadsForPeriod($coveredWeeks);
            $uploadedSourceCodes = $uploads->pluck('dataSource.code')->filter()->unique()->values();
            $missingSources = $sources->whereNotIn('code', $uploadedSourceCodes)->pluck('name')->values();
            $summary = $summariesByPeriod->get($period->id);
            $run = $runsByPeriod->get($period->id);
            $dbRun = $dbRunsByPeriod->get($period->id);

            return [
                'period_id' => $period->id,
                'period_code' => $period->code,
                'period_label' => $period->label,
                'updated_at' => optional($uploads->sortByDesc('created_at')->first()?->created_at)->format('d/m/Y H:i'),
                'uploaded_sources_count' => $uploadedSourceCodes->count(),
                'required_sources_count' => $requiredSourcesCount,
                'missing_sources_count' => $missingSources->count(),
                'processed_count' => $uploads->where('status', 'processed')->count(),
                'pending_count' => $uploads->whereIn('status', ['pending', 'processing'])->count(),
                'failed_count' => $uploads->where('status', 'failed')->count(),
                'missing_sources' => $missingSources,
                'report_final_available' => $missingSources->count() === 0 && $requiredSourcesCount > 0,
                'radiography_status' => $summary?->status ?? 'missing',
                'radiography_invalidated' => (bool) $summary?->invalidated_at,
                'radiography_run_status' => $run?->status,
                'radiography_run_log' => $run?->log,
                'radiography_run_finished_at' => optional($run?->finished_at)->format('d/m/Y H:i'),
                ...$this->resolveWorkflowState($uploads, $summary, $run, $period->type !== 'weekly', $dbRun),
                'uploads' => $uploads->unique('id')->values()->map(fn ($upload) => [
                    'id' => $upload->id,
                    'original_name' => $upload->original_name,
                    'status' => (string) ($upload->status?->value ?? $upload->status),
                    'uploaded_at' => optional($upload->created_at)->format('d/m/Y H:i'),
                    'notes' => $upload->notes,
                    'source_code' => $upload->dataSource?->code,
                    'source_name' => $upload->dataSource?->name,
                    'covered_period_ids' => $upload->covered_period_ids ?? [],
                    'covered_period_labels' => collect($upload->covered_period_ids ?? [])->map(fn ($weekId) => optional($weeklyPeriods->firstWhere('id', (int) $weekId))->label)->filter()->values(),
                ]),
            ];
        })->values();

        $currentPeriodId = ($periods->firstWhere('can_receive_uploads', true)['id'] ?? null) ?: ($periods->first()['id'] ?? null);

        $branches = Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $employees = Employee::query()->where('is_active', true)->orderBy('full_name')
            ->with(['employeeBranchAssignments' => fn ($q) => $q->with('branch:id,name')->latest()])
            ->get(['id', 'full_name', 'normalized_name'])
            ->unique(fn (Employee $e) => $e->normalized_name ?: mb_strtolower(trim($e->full_name)))
            ->values()
            ->map(fn (Employee $e) => [
                'id'          => $e->id,
                'full_name'   => $e->full_name,
                'branch_name' => $e->employeeBranchAssignments->first()?->branch?->name,
            ]);

        return Inertia::render('Historico-General/index', [
            'periods'        => $periods,
            'sources'        => $sources,
            'groupedUploads' => $groupedUploads,
            'currentPeriodId' => $currentPeriodId,
            'preview'        => $this->previewPayload($periodModels->firstWhere('id', $currentPeriodId)),
            'branches'       => $branches,
            'employees'      => $employees,
        ]);
    }

    public function store(StoreReportUploadRequest $request, ReportUploadService $service): RedirectResponse
    {
        $period = Period::query()->findOrFail((int) $request->integer('period_id'));
        if ($period->type !== 'weekly') {
            return back()->with('error', 'Este periodo es automático y no recibe archivos directos.');
        }

        $service->store((int) $request->integer('period_id'), collect($request->input('covered_period_ids', []))->map(fn ($id) => (int) $id)->values()->all(), (int) $request->integer('data_source_id'), $request->file('file'), $request->string('notes')->toString() ?: null);
        return back()->with('success', 'Archivo subido correctamente.');
    }

    public function destroy(ReportUpload $reportUpload): RedirectResponse
    {
        if ($reportUpload->stored_path && Storage::disk('public')->exists($reportUpload->stored_path)) {
            Storage::disk('public')->delete($reportUpload->stored_path);
        }
        $reportUpload->delete();
        return back()->with('success', 'Archivo eliminado correctamente.');
    }

    public function updateDatabase(Period $period): RedirectResponse
    {
        $existingRun = PeriodDatabaseUpdateRun::query()
            ->where('period_id', $period->id)
            ->whereIn('status', ['queued', 'running'])
            ->first();

        if ($existingRun) {
            return back()->with('error', 'Ya hay una actualización en proceso para este periodo. Espera a que termine.');
        }

        $coveredWeeks = $this->resolveCoveredWeeks($period, Period::query()->where('type', 'weekly')->get());
        $uploads = $this->resolveUploadsForPeriod($coveredWeeks);
        $summary = PeriodSummary::query()->where('period_id', $period->id)->first();
        $workflow = $this->resolveWorkflowState($uploads, $summary, null, $period->type !== 'weekly');

        if (!$workflow['can_update_database']) {
            return back()->with('error', implode(' ', $workflow['blocking_reasons']) ?: 'Faltan NOI y Cobranza procesables.');
        }

        $run = PeriodDatabaseUpdateRun::query()->create([
            'period_id'  => $period->id,
            'created_by' => auth()->id(),
            'status'     => 'queued',
            'log'        => 'Actualización de base de datos en cola.',
            'started_at' => now(),
        ]);

        UpdatePeriodDatabaseJob::dispatch($period->id, $run->id, auth()->id());

        return back()->with(
            'success',
            'El proceso fue enviado a cola. Puedes cerrar esta ventana; te avisaremos por correo cuando termine.'
        );
    }

    public function incidents(Period $period)
    {
        $summary = PeriodSummary::query()->where('period_id', $period->id)->with('incidents')->first();
        return response()->json([
            'items' => $summary?->incidents?->map(fn ($incident) => [
                'id' => $incident->id,
                'type' => $incident->type,
                'severity' => $incident->severity,
                'message' => $incident->message,
                'context' => $incident->context,
            ])->values() ?? [],
            'has_critical' => (bool) ($summary?->incidents?->contains(fn ($item) => $item->severity === 'high') ?? false),
        ]);
    }

    public function resolveIncident(Period $period, PeriodIncident $incident, Request $request): RedirectResponse
    {
        abort_unless($incident->periodSummary?->period_id === $period->id, 404);
        $incident->update(['severity' => 'resolved', 'context' => array_merge($incident->context ?? [], ['resolved_by' => auth()->id(), 'resolved_at' => now()->toDateTimeString(), 'resolution_note' => (string) $request->input('resolution_note', 'Resuelta manualmente.')])]);
        return back()->with('success', 'Incidencia resuelta correctamente.');
    }

    public function generateRadiography(Period $period, Request $request): RedirectResponse
    {
        $coveredWeeks = $this->resolveCoveredWeeks($period, Period::query()->where('type', 'weekly')->get());
        $uploads = $this->resolveUploadsForPeriod($coveredWeeks);
        $summary = PeriodSummary::query()->where('period_id', $period->id)->with('incidents')->first();
        $latestRun = PeriodRadiographyRun::query()->where('period_id', $period->id)->latest('id')->first();
        $workflow = $this->resolveWorkflowState($uploads, $summary, $latestRun, $period->type !== 'weekly');

        if (!$workflow['can_generate_radiography']) {
            return back()->with('error', implode(' ', $workflow['blocking_reasons']) ?: 'No se puede generar la Radiografía todavía.');
        }

        $run = PeriodRadiographyRun::query()->create([
            'period_id' => $period->id,
            'status' => 'queued',
            'started_at' => now(),
            'created_by' => auth()->id(),
            'log' => 'Radiografía en cola. Puedes cerrar esta ventana.',
        ]);

        $config = $request->input('config', []);
        $run->forceFill(['log' => 'Radiografía en cola. Configuración: ' . json_encode($config, JSON_UNESCAPED_UNICODE)])->save();

        GenerateRadiographyJob::dispatch($period->id, auth()->id(), $run->id, $config);

        return back()->with(
            'success',
            'La Radiografía se está generando. Puedes cerrar esta ventana y volver más tarde. Cuando el Excel final esté listo, se habilitará la descarga y se enviará una notificación al correo de tu usuario.'
        );
    }

    private const DB_SOURCES = ['noi_nomina', 'lendus_ingresos_cobranza'];

    public function analyze(ReportUpload $reportUpload, ReportAnalysisService $service): RedirectResponse
    {
        try {
            $service->analyze($reportUpload);

            $sourceCode = $reportUpload->dataSource?->code ?? '';
            $sourceName = $reportUpload->dataSource?->name ?? 'La fuente';
            $isBdSource = in_array($sourceCode, self::DB_SOURCES, true);

            $summary = PeriodSummary::query()
                ->where('period_id', $reportUpload->period_id)
                ->whereNull('invalidated_at')
                ->first();

            if ($summary && $isBdSource) {
                // Fuente de BD reprocesada → invalidar todo; la BD necesita re-ejecutarse
                $summary->update([
                    'invalidated_at'     => now(),
                    'invalidated_by'     => auth()->id(),
                    'invalidated_reason' => "Fuente de BD reprocesada: {$sourceName}. La actualización de BD debe ejecutarse nuevamente.",
                ]);
                return back()->with('success', "{$sourceName} fue reprocesada. La actualización de BD debe ejecutarse nuevamente.");
            }

            if ($summary && !$isBdSource && $summary->status === 'generated') {
                // Fuente solo de Radiografía reprocesada después de generar reporte → revertir a estado de BD activo
                $summary->update([
                    'status'             => 'database_updated',
                    'invalidated_reason' => "Fuente reprocesada: {$sourceName}. Re-genera la Radiografía para reflejar los cambios.",
                ]);
                return back()->with('success', "{$sourceName} fue reprocesada. La actualización de BD se conserva; re-genera la Radiografía para reflejar los cambios.");
            }

            return back()->with('success', "{$sourceName} fue reprocesada. La actualización de BD se mantiene intacta.");
        } catch (\Throwable $e) {
            return back()->with('error', 'No se pudo reprocesar el archivo: ' . mb_strimwidth($e->getMessage(), 0, 300));
        }
    }

    public function cancelDatabaseUpdate(Period $period): RedirectResponse
    {
        $run = PeriodDatabaseUpdateRun::query()
            ->where('period_id', $period->id)
            ->whereIn('status', ['queued', 'running'])
            ->latest('id')
            ->first();

        if (!$run) {
            return back()->with('error', 'No hay un proceso activo para cancelar en este periodo.');
        }

        $cancelledBy = auth()->user()?->name ?? 'usuario';
        $run->update([
            'status'        => 'failed',
            'finished_at'   => now(),
            'log'           => "Proceso cancelado manualmente por {$cancelledBy}.",
            'error_message' => 'Cancelado manualmente.',
        ]);

        return back()->with('success', 'El proceso fue cancelado. Puedes reintentarlo cuando estés listo.');
    }

    private function previewPayload(?Period $period): array
    {
        if (!$period) {
            return ['metrics' => [], 'employees' => []];
        }

        $summary = MonthlyEmployeeSummary::query()
            ->where('period_id', $period->id)
            ->selectRaw('COUNT(*) as total_empleados')
            ->selectRaw('SUM(total_expenses) as gasto_total')
            ->selectRaw('SUM(net_amount) as neto_total')
            ->selectRaw('SUM(total_payments) as pagos_total')
            ->first();

        return [
            'metrics' => [
                'total_empleados' => (int) ($summary->total_empleados ?? 0),
                'gasto_total' => (float) ($summary->gasto_total ?? 0),
                'neto_total' => (float) ($summary->neto_total ?? 0),
                'pagos_total' => (float) ($summary->pagos_total ?? 0),
            ],
            'employees' => MonthlyEmployeeSummary::query()->with(['employee:id,full_name', 'branch:id,name'])->where('period_id', $period->id)->limit(50)->get()->map(fn (MonthlyEmployeeSummary $row) => [
                'id' => $row->id,
                'employee_name' => $row->employee?->full_name ?? 'Sin empleado',
                'branch_name' => $row->branch?->name,
                'total_payments' => (float) $row->total_payments,
                'total_expenses' => (float) $row->total_expenses,
                'net_amount' => (float) $row->net_amount,
            ])->values(),
        ];
    }

    private function resolveCoveredWeeks(Period $period, Collection $weeklyPeriods): Collection
    {
        if ($period->type === 'weekly') return $weeklyPeriods->where('id', $period->id)->values();
        return $weeklyPeriods->filter(fn ($week) => $week->start_date && $week->end_date && $period->start_date && $period->end_date && $week->start_date->lte($period->end_date) && $week->end_date->gte($period->start_date))->values();
    }

    private function resolveUploadsForPeriod(Collection $coveredWeeks): Collection
    {
        if ($coveredWeeks->isEmpty()) return collect();
        $weekIds = $coveredWeeks->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        return ReportUpload::query()->with('dataSource:id,code,name')->get()->filter(fn (ReportUpload $upload) => collect($upload->covered_period_ids ?? [])->map(fn ($id) => (int) $id)->intersect($weekIds)->isNotEmpty())->sortByDesc('created_at')->values();
    }

    private function resolveWorkflowState(Collection $uploads, ?PeriodSummary $summary, ?PeriodRadiographyRun $run = null, bool $derivedPeriod = false, ?PeriodDatabaseUpdateRun $dbRun = null): array
    {
        $requiredRadiographySources = ['noi_nomina', 'lendus_ingresos_cobranza', 'gastos', 'lendus_ministraciones', 'lendus_saldos_cliente'];
        $sourceCodes = $uploads->pluck('dataSource.code')->filter()->unique()->values();
        $missingDb = collect(['noi_nomina', 'lendus_ingresos_cobranza'])->filter(fn ($code) => !$sourceCodes->contains($code))->values()->all();
        $missingRadiography = collect($requiredRadiographySources)->filter(fn ($code) => !$sourceCodes->contains($code))->values()->all();
        $failedDb = collect(['noi_nomina', 'lendus_ingresos_cobranza'])->filter(function ($code) use ($uploads) {
            $upload = $uploads->first(fn ($item) => $item->dataSource?->code === $code);
            return $upload && (string) ($upload->status?->value ?? $upload->status) === 'failed';
        })->values()->all();
        $unprocessedRadiography = collect($requiredRadiographySources)->filter(function ($code) use ($uploads) {
            $upload = $uploads->first(fn ($item) => $item->dataSource?->code === $code);
            if (!$upload) return false;
            return (string) ($upload->status?->value ?? $upload->status) !== 'processed';
        })->values()->all();
        $pendingCritical = (int) ($summary?->incidents()->where('severity', 'high')->count() ?? 0);
        $databaseUpdated = (in_array($summary?->status, ['database_updated', 'generated'], true) && !$summary?->invalidated_at) || ($derivedPeriod && empty($missingDb) && empty($failedDb));
        $radiographyReady = ($summary?->status === 'generated') && !$summary?->invalidated_at;
        $runStatus = $run?->status;
        $running = in_array($runStatus, ['queued', 'running'], true);
        $dbRunStatus = $dbRun?->status;
        $dbRunning = in_array($dbRunStatus, ['queued', 'running'], true);

        // Stuck detection — queued sin iniciar > 5 min, running > 30 min
        $dbElapsedMinutes = null;
        $dbStuckWarning   = false;
        if ($dbRun && $dbRunning) {
            if ($dbRunStatus === 'queued') {
                $ref = $dbRun->created_at;
                $dbElapsedMinutes = $ref ? max(0, (int) now()->diffInMinutes($ref)) : null;
                $dbStuckWarning   = $dbElapsedMinutes !== null && $dbElapsedMinutes >= 5;
            } else {
                $ref = $dbRun->started_at ?? $dbRun->created_at;
                $dbElapsedMinutes = $ref ? max(0, (int) now()->diffInMinutes($ref)) : null;
                $dbStuckWarning   = $dbElapsedMinutes !== null && $dbElapsedMinutes >= 30;
            }
        }

        $blockingReasons = [];
        if (!empty($missingDb)) $blockingReasons[] = 'No se puede actualizar la BD. Faltan archivos obligatorios: NOI Nómina, Lendus Ingresos Cobranza.';
        if (!empty($failedDb)) $blockingReasons[] = 'No se puede actualizar la BD porque NOI o Cobranza tienen error de procesamiento.';
        if ($dbRunning) $blockingReasons[] = 'La actualización de base de datos está en proceso.';
        if (!$databaseUpdated) $blockingReasons[] = 'Primero actualiza la BD.';
        if ($pendingCritical > 0) $blockingReasons[] = 'Hay incidencias pendientes antes de generar la Radiografía.';
        if (!empty($missingRadiography)) $blockingReasons[] = 'Faltan fuentes para analizar y generar Radiografía.';
        if (!empty($unprocessedRadiography)) $blockingReasons[] = 'Hay fuentes pendientes, procesando o con error. Todas deben quedar procesadas.';
        if ($running) $blockingReasons[] = 'La Radiografía está en proceso. Puedes cerrar esta ventana y volver más tarde.';

        $previewSummary = null;
        if ($radiographyReady && $summary) {
            $gm = $summary->global_metrics ?? [];
            $previewSummary = [
                'global_metrics' => $gm,
                'generated_at'   => optional($summary->generated_at)->format('d/m/Y H:i'),
                'version'        => $summary->version,
            ];
        }

        return [
            'database_updated'   => $databaseUpdated,
            'database_invalidated' => (bool) $summary?->invalidated_at,
            'database_update_run_status'      => $dbRunStatus,
            'database_update_run_log'         => $dbRun?->log ? mb_strimwidth($dbRun->log, 0, 300) : null,
            'database_update_run_error'       => $dbRun?->error_message ? mb_strimwidth($dbRun->error_message, 0, 300) : null,
            'database_update_run_started_at'  => optional($dbRun?->started_at)->format('d/m/Y H:i'),
            'database_update_run_finished_at' => optional($dbRun?->finished_at)->format('d/m/Y H:i'),
            'database_update_run_metadata'    => $dbRun?->metadata,
            'database_update_elapsed_minutes' => $dbElapsedMinutes,
            'database_update_stuck_warning'   => $dbStuckWarning,
            'pending_critical_incidents_count' => $pendingCritical,
            'missing_database_sources' => $missingDb,
            'missing_radiography_sources' => $missingRadiography,
            'unprocessed_radiography_sources' => $unprocessedRadiography,
            'radiography_ready'      => $radiographyReady,
            'radiography_invalidated' => (bool) $summary?->invalidated_at,
            'radiography_running'    => $running,
            'can_update_database'    => empty($missingDb) && empty($failedDb) && !$dbRunning,
            'can_resolve_incidents'  => $databaseUpdated,
            'can_generate_radiography' => $databaseUpdated && $pendingCritical === 0 && empty($missingRadiography) && empty($unprocessedRadiography) && !$running,
            'can_export_radiography'   => $radiographyReady && empty($missingRadiography) && empty($unprocessedRadiography) && !$running,
            'blocking_reasons'         => array_values(array_unique($blockingReasons)),
            'preview_summary'          => $previewSummary,
        ];
    }
}
