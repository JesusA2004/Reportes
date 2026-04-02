<?php

namespace App\Http\Controllers;

use App\Enums\DataSourceCode;
use App\Http\Requests\StoreReportUploadRequest;
use App\Jobs\ImportNoiNominaJob;
use App\Models\DataSource;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Services\ReportUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ReportUploadController extends Controller {

    public function index(): Response {
        $sources = DataSource::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'description']);
        $requiredSourcesCount = $sources->count();
        $periodModels = Period::query()
            ->with([
                'reportUploads' => fn ($query) => $query
                    ->with('dataSource:id,code,name')
                    ->latest(),
            ])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get();
        $periods = $periodModels
            ->map(function ($period) use ($requiredSourcesCount) {
                $uploads = $period->reportUploads;
                $uploadedSourceCodes = $uploads
                    ->pluck('dataSource.code')
                    ->filter()
                    ->unique()
                    ->values();
                $processedCount = $uploads->where('status', 'processed')->count();
                $pendingCount = $uploads->whereIn('status', ['pending', 'processing'])->count();
                $failedCount = $uploads->where('status', 'failed')->count();
                $missingSources = DataSource::query()
                    ->where('is_active', true)
                    ->whereNotIn('code', $uploadedSourceCodes)
                    ->orderBy('name')
                    ->pluck('name')
                    ->values();
                return [
                    'id' => $period->id,
                    'code' => $period->code,
                    'label' => $period->label,
                    'year' => $period->year,
                    'month' => $period->month,
                    'is_closed' => (bool) $period->is_closed,
                    'updated_at' => optional($uploads->first()?->created_at)->format('d/m/Y H:i'),
                    'uploaded_sources_count' => $uploadedSourceCodes->count(),
                    'required_sources_count' => $requiredSourcesCount,
                    'missing_sources_count' => $missingSources->count(),
                    'processed_count' => $processedCount,
                    'pending_count' => $pendingCount,
                    'failed_count' => $failedCount,
                    'missing_sources' => $missingSources,
                    'report_final_available' => $missingSources->count() === 0 && $requiredSourcesCount > 0,
                ];
            })
            ->values();
        $groupedUploads = $periodModels
            ->map(function ($period) use ($requiredSourcesCount) {
                $uploads = $period->reportUploads;
                $uploadedSourceCodes = $uploads
                    ->pluck('dataSource.code')
                    ->filter()
                    ->unique()
                    ->values();
                $missingSources = DataSource::query()
                    ->where('is_active', true)
                    ->whereNotIn('code', $uploadedSourceCodes)
                    ->orderBy('name')
                    ->pluck('name')
                    ->values();
                return [
                    'period_id' => $period->id,
                    'period_code' => $period->code,
                    'period_label' => $period->label,
                    'updated_at' => optional($uploads->first()?->created_at)->format('d/m/Y H:i'),
                    'uploaded_sources_count' => $uploadedSourceCodes->count(),
                    'required_sources_count' => $requiredSourcesCount,
                    'missing_sources_count' => $missingSources->count(),
                    'processed_count' => $uploads->where('status', 'processed')->count(),
                    'pending_count' => $uploads->whereIn('status', ['pending', 'processing'])->count(),
                    'failed_count' => $uploads->where('status', 'failed')->count(),
                    'missing_sources' => $missingSources,
                    'report_final_available' => $missingSources->count() === 0 && $requiredSourcesCount > 0,
                    'uploads' => $uploads->map(function ($upload) {
                        return [
                            'id' => $upload->id,
                            'original_name' => $upload->original_name,
                            'status' => $upload->status,
                            'uploaded_at' => optional($upload->created_at)->format('d/m/Y H:i'),
                            'notes' => $upload->notes,
                            'source_code' => $upload->dataSource?->code,
                            'source_name' => $upload->dataSource?->name,
                        ];
                    })->values(),
                ];
            })
            ->values();
        return Inertia::render('Historico-General/Index', [
            'periods' => $periods,
            'sources' => $sources,
            'groupedUploads' => $groupedUploads,
            'currentPeriodId' => $periods->first()['id'] ?? null,
        ]);
    }

    public function store(
        StoreReportUploadRequest $request,
        ReportUploadService $service
    ): RedirectResponse {
        $upload = $service->store(
            periodId: (int) $request->integer('period_id'),
            dataSourceId: (int) $request->integer('data_source_id'),
            file: $request->file('file'),
            notes: $request->string('notes')->toString() ?: null,
        );

        $source = DataSource::findOrFail($upload->data_source_id);

        if ($source->code === DataSourceCode::NoiNomina->value) {
            ImportNoiNominaJob::dispatch($upload->id);
        }

        return back()->with('success', 'Archivo subido correctamente.');
    }

    public function destroy(ReportUpload $reportUpload): RedirectResponse
    {
        if ($reportUpload->file_path && Storage::disk('public')->exists($reportUpload->file_path)) {
            Storage::disk('public')->delete($reportUpload->file_path);
        }
        $reportUpload->delete();
        return back()->with('success', 'Archivo eliminado correctamente.');
    }

}
