<?php

use App\Enums\ReportUploadStatus;
use App\Models\DataSource;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('allows analyzing an upload from historico general route', function () {
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
EMP001,Juan Pérez,Bono,percepcion,1500,1,ordinaria,2026-04-03
CSV;

    $storedPath = 'report_uploads/test_noi_route.csv';
    Storage::disk('public')->put($storedPath, $csv);

    $upload = ReportUpload::query()->create([
        'period_id' => $period->id,
        'data_source_id' => $source->id,
        'original_name' => 'test_noi_route.csv',
        'stored_path' => $storedPath,
        'mime_type' => 'text/csv',
        'file_size' => Storage::disk('public')->size($storedPath),
        'uploaded_by' => $user->id,
        'uploaded_at' => now(),
        'status' => ReportUploadStatus::Pending,
        'notes' => null,
    ]);

    $this->actingAs($user)
        ->post("/historico-general/{$upload->id}/analizar")
        ->assertRedirect();

    expect($upload->fresh()->status->value)->toBe('processed');
});
