<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReportUploadRequest;
use App\Models\DataSource;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Services\ReportAnalysisService;
use App\Services\ReportUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ReportUploadController extends Controller
{
    public function index(): Response
    {
        $sources = DataSource::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'description']);

        $requiredSourcesCount = $sources->count();

        $periodModels = Period::query()
            ->with([
                'reportUploads' => fn ($query) => $query
                    ->with('dataSource:id,code,name')
                    ->with(['processRuns' => fn ($processQuery) => $processQuery->latest()])
                    ->latest(),
            ])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderByDesc('sequence')
            ->get();

        $weeklyPeriods = $periodModels->where('type', 'weekly')->values();

        $periods = $periodModels
            ->map(function (Period $period) use ($weeklyPeriods, $sources, $requiredSourcesCount) {
                $coveredWeeks = $this->resolveCoveredWeeks($period, $weeklyPeriods);
                $uploads = $this->resolveUploadsForPeriod($period, $coveredWeeks);

                $uploadedSourceCodes = $uploads
                    ->pluck('dataSource.code')
                    ->filter()
                    ->unique()
                    ->values();

                $processedCount = $uploads->where('status', 'processed')->count();
                $pendingCount = $uploads->whereIn('status', ['pending', 'processing'])->count();
                $failedCount = $uploads->where('status', 'failed')->count();

                $missingSources = $sources
                    ->whereNotIn('code', $uploadedSourceCodes)
                    ->pluck('name')
                    ->values();

                $availableWeekOptions = $period->type === 'weekly'
                    ? $weeklyPeriods
                        ->where('year', $period->year)
                        ->where('month', $period->month)
                        ->sortBy('sequence')
                        ->map(fn ($week) => [
                            'id' => $week->id,
                            'label' => $week->label,
                            'sequence' => $week->sequence,
                            'start_date' => optional($week->start_date)->format('Y-m-d'),
                            'end_date' => optional($week->end_date)->format('Y-m-d'),
                        ])
                        ->values()
                    : collect();

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
                    'processed_count' => $processedCount,
                    'pending_count' => $pendingCount,
                    'failed_count' => $failedCount,
                    'missing_sources' => $missingSources,
                    'report_final_available' => $missingSources->count() === 0 && $requiredSourcesCount > 0,
                    'available_week_options' => $availableWeekOptions,
                ];
            })
            ->values();

        $groupedUploads = $periodModels
            ->map(function (Period $period) use ($weeklyPeriods, $sources, $requiredSourcesCount) {
                $coveredWeeks = $this->resolveCoveredWeeks($period, $weeklyPeriods);
                $uploads = $this->resolveUploadsForPeriod($period, $coveredWeeks);

                $uploadedSourceCodes = $uploads
                    ->pluck('dataSource.code')
                    ->filter()
                    ->unique()
                    ->values();

                $missingSources = $sources
                    ->whereNotIn('code', $uploadedSourceCodes)
                    ->pluck('name')
                    ->values();

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
                    'uploads' => $uploads
                        ->unique('id')
                        ->values()
                        ->map(function ($upload) use ($weeklyPeriods) {
                            $coveredWeekLabels = collect($upload->covered_period_ids ?? [])
                                ->map(function ($weekId) use ($weeklyPeriods) {
                                    return optional($weeklyPeriods->firstWhere('id', (int) $weekId))->label;
                                })
                                ->filter()
                                ->values();

                            return [
                                'id' => $upload->id,
                                'original_name' => $upload->original_name,
                                'status' => $upload->status,
                                'uploaded_at' => optional($upload->created_at)->format('d/m/Y H:i'),
                                'notes' => $upload->notes,
                                'source_code' => $upload->dataSource?->code,
                                'source_name' => $upload->dataSource?->name,
                                'covered_period_ids' => $upload->covered_period_ids ?? [],
                                'covered_period_labels' => $coveredWeekLabels,
                                'last_process_run' => $upload->processRuns->first() ? [
                                    'status' => $upload->processRuns->first()->status?->value ?? $upload->processRuns->first()->status,
                                    'rows_read' => $upload->processRuns->first()->rows_read,
                                    'rows_inserted' => $upload->processRuns->first()->rows_inserted,
                                    'rows_with_errors' => $upload->processRuns->first()->rows_with_errors,
                                    'finished_at' => optional($upload->processRuns->first()->finished_at)->format('d/m/Y H:i'),
                                ] : null,
                            ];
                        }),
                ];
            })
            ->values();

        return Inertia::render('Historico-General/index', [
            'periods' => $periods,
            'sources' => $sources,
            'groupedUploads' => $groupedUploads,
            'currentPeriodId' => $periods->firstWhere('can_receive_uploads', true)['id'] ?? $periods->first()['id'] ?? null,
        ]);
    }

    public function store(
        StoreReportUploadRequest $request,
        ReportUploadService $service
    ): RedirectResponse {
        $service->store(
            periodId: (int) $request->integer('period_id'),
            coveredPeriodIds: collect($request->input('covered_period_ids', []))
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
            dataSourceId: (int) $request->integer('data_source_id'),
            file: $request->file('file'),
            notes: $request->string('notes')->toString() ?: null,
        );

        return back()->with('success', 'Archivo subido correctamente. Ahora puedes analizarlo.');
    }

    public function destroy(ReportUpload $reportUpload): RedirectResponse
    {
        if ($reportUpload->stored_path && Storage::disk('public')->exists($reportUpload->stored_path)) {
            Storage::disk('public')->delete($reportUpload->stored_path);
        }

        $reportUpload->delete();

        return back()->with('success', 'Archivo eliminado correctamente.');
    }

    public function analyze(ReportUpload $reportUpload, ReportAnalysisService $analysisService): RedirectResponse
    {
        $analysisService->analyze($reportUpload);

        return back()->with('success', 'Archivo analizado correctamente.');
    }

    private function resolveCoveredWeeks(Period $period, Collection $weeklyPeriods): Collection
    {
        if ($period->type === 'weekly') {
            return $weeklyPeriods->where('id', $period->id)->values();
        }

        return $weeklyPeriods
            ->filter(function ($week) use ($period) {
                if (!$week->start_date || !$week->end_date || !$period->start_date || !$period->end_date) {
                    return false;
                }

                return $week->start_date->lte($period->end_date)
                    && $week->end_date->gte($period->start_date);
            })
            ->values();
    }

    private function resolveUploadsForPeriod(Period $period, Collection $coveredWeeks): Collection
    {
        if ($coveredWeeks->isEmpty()) {
            return collect();
        }

        $weekIds = $coveredWeeks->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        return ReportUpload::query()
            ->with('dataSource:id,code,name')
            ->with(['processRuns' => fn ($processQuery) => $processQuery->latest()])
            ->get()
            ->filter(function (ReportUpload $upload) use ($weekIds) {
                $covered = collect($upload->covered_period_ids ?? [])
                    ->map(fn ($id) => (int) $id);

                return $covered->intersect($weekIds)->isNotEmpty();
            })
            ->sortByDesc('created_at')
            ->values();
    }
}
