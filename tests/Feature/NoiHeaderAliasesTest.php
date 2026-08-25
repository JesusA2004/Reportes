<?php

use App\Enums\ReportUploadStatus;
use App\Models\DataSource;
use App\Models\Employee;
use App\Models\NoiMovement;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Models\User;
use App\Services\ReportAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Bug real (VPS, julio 2026): un NOI con encabezados NOMBRE_TRAB / DESCRIP_CPTO /
 * "Monto del periodo" fallaba con "El archivo NOI no contiene columnas mínimas
 * requeridas: concept, amount" porque NoiNominaImportService::buildHeaderMap() no
 * reconocía esos alias — ver App\Services\Imports\NoiNominaImportService.
 */
function makeNoiPeriodo(): Period
{
    return Period::query()->create([
        'name' => 'Semana 1 - Julio 2026',
        'code' => 'W-2026-07-01',
        'type' => 'weekly',
        'year' => 2026,
        'month' => 7,
        'sequence' => 1,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-05',
        'is_closed' => false,
    ]);
}

function makeNoiUpload(Period $period, string $csv, string $filename): ReportUpload
{
    $user = User::query()->create([
        'name' => 'Tester', 'email' => "tester+{$filename}@example.com", 'password' => bcrypt('secret'),
    ]);

    $source = DataSource::query()->firstOrCreate(
        ['code' => 'noi_nomina'],
        ['name' => 'NOI Nómina', 'description' => 'Importación NOI', 'is_active' => true],
    );

    $storedPath = "report_uploads/{$filename}";
    Storage::disk('public')->put($storedPath, $csv);

    return ReportUpload::query()->create([
        'period_id' => $period->id,
        'data_source_id' => $source->id,
        'original_name' => $filename,
        'stored_path' => $storedPath,
        'mime_type' => 'text/csv',
        'file_size' => Storage::disk('public')->size($storedPath),
        'uploaded_by' => $user->id,
        'uploaded_at' => now(),
        'status' => ReportUploadStatus::Pending,
        'notes' => null,
    ]);
}

it('imports a NOI file using the new VPS header format (NOMBRE_TRAB / DESCRIP_CPTO / Monto del periodo)', function () {
    Storage::fake('public');

    $period = makeNoiPeriodo();

    $csv = <<<CSV
NOMBRE_TRAB,DESCRIP_CPTO,Monto del periodo
Juan Perez,Sueldo,1500
Ana Lopez,Descuento,250
CSV;

    $upload = makeNoiUpload($period, $csv, 'noi_nuevo_formato.csv');

    $run = app(ReportAnalysisService::class)->analyze($upload->fresh('dataSource'));

    expect($run->rows_read)->toBe(2);
    expect($run->rows_inserted)->toBe(2);
    expect($run->rows_with_errors)->toBe(0);
    expect(Employee::query()->count())->toBe(2);
    expect(NoiMovement::query()->count())->toBe(2);

    $juan = NoiMovement::query()->whereHas('employee', fn ($q) => $q->where('full_name', 'Juan Perez'))->first();
    expect($juan)->not->toBeNull();
    expect((float) $juan->amount)->toBe(1500.0);
    expect($juan->concept)->toBe('Sueldo');
});

it('still imports the previous NOI header format (positional nombre_empleado / concepto / importe)', function () {
    Storage::fake('public');

    $period = makeNoiPeriodo();

    $csv = <<<CSV
codigo_empleado,nombre_empleado,concepto,tipo_concepto,importe,cantidad,tipo_nomina,fecha
EMP001,Juan Perez,Bono,percepcion,1500,1,ordinaria,2026-07-03
EMP002,Ana Lopez,Descuento,deduccion,250,1,ordinaria,2026-07-04
CSV;

    $upload = makeNoiUpload($period, $csv, 'noi_formato_anterior.csv');

    $run = app(ReportAnalysisService::class)->analyze($upload->fresh('dataSource'));

    expect($run->rows_read)->toBe(2);
    expect($run->rows_inserted)->toBe(2);
    expect($run->rows_with_errors)->toBe(0);
    expect(Employee::query()->count())->toBe(2);
    expect(NoiMovement::query()->count())->toBe(2);
});
