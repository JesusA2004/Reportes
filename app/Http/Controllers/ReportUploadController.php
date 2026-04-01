<?php

namespace App\Http\Controllers;

use App\Enums\DataSourceCode;
use App\Http\Requests\StoreReportUploadRequest;
use App\Jobs\ImportNoiNominaJob;
use App\Models\DataSource;
use App\Models\Period;
use App\Services\ReportUploadService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ReportUploadController extends Controller {

    public function index(): Response {
        return Inertia::render('historico-general/index', [
            'periods' => Period::query()
                ->orderByDesc('year')
                ->orderByDesc('month')
                ->get(),
            'sources' => DataSource::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
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

}
