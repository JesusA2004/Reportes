<?php

use App\Enums\ReportUploadStatus;
use App\Models\DataSource;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Models\User;
use App\Services\ReportAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('fails clearly when trying to analyze an unsupported source', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $period = Period::query()->create([
        'name' => 'Semana 1 - Abril 2026',
        'code' => 'W-2026-04-01',
        'type' => 'weekly',
        'year' => 2026,
        'month' => 4,
        'sequence' => 1,
        'start_date' => '2026-04-01',
        'end_date' => '2026-04-05',
        'is_closed' => false,
    ]);

    $source = DataSource::query()->create([
        'code' => 'macro_analisis',
        'name' => 'Macro análisis',
        'description' => 'Fuente no implementada aún',
        'is_active' => true,
    ]);

    $storedPath = 'report_uploads/test_macro.csv';
    Storage::disk('public')->put($storedPath, "col1,col2\nA,B");

    $upload = ReportUpload::query()->create([
        'period_id' => $period->id,
        'data_source_id' => $source->id,
        'original_name' => 'test_macro.csv',
        'stored_path' => $storedPath,
        'mime_type' => 'text/csv',
        'file_size' => Storage::disk('public')->size($storedPath),
        'uploaded_by' => $user->id,
        'uploaded_at' => now(),
        'status' => ReportUploadStatus::Pending,
        'notes' => null,
    ]);

    $service = app(ReportAnalysisService::class);

    expect(fn () => $service->analyze($upload->fresh('dataSource')))
        ->toThrow(RuntimeException::class, 'aún no tiene importador implementado');
});
