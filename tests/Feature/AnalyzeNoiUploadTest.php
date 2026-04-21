<?php

use App\Enums\ReportUploadStatus;
use App\Models\DataSource;
use App\Models\Employee;
use App\Models\NoiMovement;
use App\Models\Period;
use App\Models\ProcessRun;
use App\Models\ReportUpload;
use App\Models\User;
use App\Services\ReportAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('analyzes a noi_nomina upload and creates employees and noi movements', function () {
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
        'code' => 'noi_nomina',
        'name' => 'NOI Nómina',
        'description' => 'Importación NOI',
        'is_active' => true,
    ]);

    $csv = <<<CSV
codigo_empleado,nombre_empleado,concepto,tipo_concepto,importe,cantidad,tipo_nomina,fecha
EMP001,Juan Perez,Bono,percepcion,1500,1,ordinaria,2026-04-03
EMP002,Ana Lopez,Descuento,deduccion,250,1,ordinaria,2026-04-04
CSV;

    $storedPath = 'report_uploads/test_noi_nomina.csv';
    Storage::disk('public')->put($storedPath, $csv);

    $upload = ReportUpload::query()->create([
        'period_id' => $period->id,
        'data_source_id' => $source->id,
        'original_name' => 'test_noi_nomina.csv',
        'stored_path' => $storedPath,
        'mime_type' => 'text/csv',
        'file_size' => Storage::disk('public')->size($storedPath),
        'uploaded_by' => $user->id,
        'uploaded_at' => now(),
        'status' => ReportUploadStatus::Pending,
        'notes' => null,
    ]);

    $service = app(ReportAnalysisService::class);
    $run = $service->analyze($upload->fresh('dataSource'));

    expect($run->rows_read)->toBe(2);
    expect($run->rows_inserted)->toBe(2);
    expect($run->rows_skipped)->toBe(0);
    expect($run->rows_with_errors)->toBe(0);

    expect(ProcessRun::query()->count())->toBe(1);
    expect(Employee::query()->count())->toBe(2);
    expect(NoiMovement::query()->count())->toBe(2);

    expect($upload->fresh()->status->value)->toBe('processed');
});
