<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReportUploadRequest;
use App\Models\DataSource;
use App\Models\Period;
use App\Models\PeriodIncident;
use App\Models\PeriodSummary;
use App\Models\ReportUpload;
use App\Services\DatabaseUpdateService;
use App\Services\PeriodRadiographyService;
use App\Services\ReportAnalysisService;
use App\Services\ReportUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ReportUploadController extends Controller
{
    public function index(): Response
    {
        $sources = DataSource::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'description']);
        $requiredSourcesCount = $sources->count();
        $summariesByPeriod = PeriodSummary::query()->with('incidents')->get()->keyBy('period_id');

        $periodModels = Period::query()->orderByDesc('year')->orderByDesc('month')->orderByDesc('sequence')->get();
        $weeklyPeriods = $periodModels->where('type', 'weekly')->values();

        $periods = $periodModels->map(function (Period $period) use ($weeklyPeriods, $sources, $requiredSourcesCount, $summariesByPeriod) {
            $coveredWeeks = $this->resolveCoveredWeeks($period, $weeklyPeriods);
            $uploads = $this->resolveUploadsForPeriod($coveredWeeks);
            $uploadedSourceCodes = $uploads->pluck('dataSource.code')->filter()->unique()->values();
            $missingSources = $sources->whereNotIn('code', $uploadedSourceCodes)->pluck('name')->values();

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
                'updated_at' => optional($uploads->sortByDesc('created_at')->first()?->created_at)->format('d/m/Y H:i'),
                'uploaded_sources_count' => $uploadedSourceCodes->count(),
                'required_sources_count' => $requiredSourcesCount,
                'missing_sources_count' => $missingSources->count(),
                'processed_count' => $uploads->where('status', 'processed')->count(),
                'pending_count' => $uploads->whereIn('status', ['pending', 'processing'])->count(),
                'failed_count' => $uploads->where('status', 'failed')->count(),
                'missing_sources' => $missingSources,
                'report_final_available' => $missingSources->count() === 0 && $requiredSourcesCount > 0,
                'radiography_status' => $summariesByPeriod->get($period->id)?->status ?? 'missing',
                'radiography_invalidated' => (bool) $summariesByPeriod->get($period->id)?->invalidated_at,
                ...$this->resolveWorkflowState($uploads, $summariesByPeriod->get($period->id)),
                'available_week_options' => $period->type === 'weekly' ? $weeklyPeriods->where('year', $period->year)->where('month', $period->month)->sortBy('sequence')->map(fn ($week) => [
                    'id' => $week->id,
                    'label' => $week->label,
                    'sequence' => $week->sequence,
                    'start_date' => optional($week->start_date)->format('Y-m-d'),
                    'end_date' => optional($week->end_date)->format('Y-m-d'),
                ])->values() : collect(),
            ];
        })->values();

        $groupedUploads = $periodModels->map(function (Period $period) use ($weeklyPeriods, $sources, $requiredSourcesCount, $summariesByPeriod) {
            $coveredWeeks = $this->resolveCoveredWeeks($period, $weeklyPeriods);
            $uploads = $this->resolveUploadsForPeriod($coveredWeeks);
            $uploadedSourceCodes = $uploads->pluck('dataSource.code')->filter()->unique()->values();
            $missingSources = $sources->whereNotIn('code', $uploadedSourceCodes)->pluck('name')->values();

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
                'radiography_status' => $summariesByPeriod->get($period->id)?->status ?? 'missing',
                'radiography_invalidated' => (bool) $summariesByPeriod->get($period->id)?->invalidated_at,
                ...$this->resolveWorkflowState($uploads, $summariesByPeriod->get($period->id)),
                'uploads' => $uploads->unique('id')->values()->map(fn ($upload) => [
                    'id' => $upload->id,
                    'original_name' => $upload->original_name,
                    'status' => $upload->status,
                    'uploaded_at' => optional($upload->created_at)->format('d/m/Y H:i'),
                    'notes' => $upload->notes,
                    'source_code' => $upload->dataSource?->code,
                    'source_name' => $upload->dataSource?->name,
                    'covered_period_ids' => $upload->covered_period_ids ?? [],
                    'covered_period_labels' => collect($upload->covered_period_ids ?? [])->map(fn ($weekId) => optional($weeklyPeriods->firstWhere('id', (int) $weekId))->label)->filter()->values(),
                ]),
            ];
        })->values();

        return Inertia::render('Historico-General/index', [
            'periods' => $periods,
            'sources' => $sources,
            'groupedUploads' => $groupedUploads,
            'currentPeriodId' => $periods->firstWhere('can_receive_uploads', true)['id'] ?? $periods->first()['id'] ?? null,
        ]);
    }

    public function store(StoreReportUploadRequest $request, ReportUploadService $service): RedirectResponse
    {
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

    public function updateDatabase(Period $period, DatabaseUpdateService $service): RedirectResponse
    {
        $service->updateForPeriod($period);
        return back()->with('success', 'BD actualizada correctamente. Revisa incidencias pendientes.');
    }

    public function incidents(Period $period)
    {
        $summary = PeriodSummary::query()->where('period_id', $period->id)->with('incidents')->first();
        return response()->json([
            'items' => $summary?->incidents?->map(fn ($incident) => ['id' => $incident->id, 'type' => $incident->type, 'severity' => $incident->severity, 'message' => $incident->message])->values() ?? [],
            'has_critical' => (bool) ($summary?->incidents?->contains(fn ($item) => $item->severity === 'high') ?? false),
        ]);
    }

    public function resolveIncident(Period $period, PeriodIncident $incident, Request $request): RedirectResponse
    {
        abort_unless($incident->periodSummary?->period_id === $period->id, 404);
        $incident->update(['severity' => 'resolved', 'context' => array_merge($incident->context ?? [], ['resolved_by' => auth()->id(), 'resolved_at' => now()->toDateTimeString(), 'resolution_note' => (string) $request->input('resolution_note', 'Resuelta manualmente.')])]);
        return back()->with('success', 'Incidencia resuelta correctamente.');
    }

    public function generateRadiography(Period $period, PeriodRadiographyService $service): RedirectResponse
    {
        $service->generate($period, auth()->id());
        return back()->with('success', 'Radiografía generada correctamente.');
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

    private function resolveWorkflowState(Collection $uploads, ?PeriodSummary $summary): array
    {
        $sourceCodes = $uploads->pluck('dataSource.code')->filter()->unique()->values();
        $missingDb = collect(['noi_nomina', 'lendus_ingresos_cobranza'])->filter(fn ($code) => !$sourceCodes->contains($code))->values()->all();
        $missingRadiography = collect(['noi_nomina', 'lendus_ingresos_cobranza', 'gastos', 'lendus_ministraciones', 'lendus_saldos_cliente'])->filter(fn ($code) => !$sourceCodes->contains($code))->values()->all();
        $pendingCritical = (int) ($summary?->incidents()->where('severity', 'high')->count() ?? 0);
        $databaseUpdated = ($summary?->status === 'database_updated') && !$summary?->invalidated_at;
        $radiographyReady = ($summary?->status === 'generated') && !$summary?->invalidated_at;

        $blockingReasons = [];
        if (!empty($missingDb)) $blockingReasons[] = 'No se puede actualizar la BD. Faltan archivos obligatorios: NOI Nómina, Lendus Ingresos Cobranza.';
        if (!$databaseUpdated) $blockingReasons[] = 'La BD debe actualizarse nuevamente porque cambió una fuente base.';
        if ($pendingCritical > 0) $blockingReasons[] = 'Hay incidencias pendientes antes de generar la Radiografía.';
        if (!empty($missingRadiography)) $blockingReasons[] = 'Faltan fuentes para analizar y generar Radiografía.';

        return [
            'database_updated' => $databaseUpdated,
            'pending_critical_incidents_count' => $pendingCritical,
            'missing_database_sources' => $missingDb,
            'missing_radiography_sources' => $missingRadiography,
            'radiography_ready' => $radiographyReady,
            'can_update_database' => empty($missingDb),
            'can_resolve_incidents' => $databaseUpdated,
            'can_generate_radiography' => $databaseUpdated && $pendingCritical === 0 && empty($missingRadiography),
            'can_export_radiography' => $radiographyReady,
            'blocking_reasons' => array_values(array_unique($blockingReasons)),
        ];
    }
}
