<?php

use App\Enums\ReportUploadStatus;
use App\Models\DataSource;
use App\Models\Employee;
use App\Models\LendusEmployeeDirectory;
use App\Models\NoiMovement;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Models\User;
use App\Services\ReportAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function makeNoiPeriodAndSource(): array
{
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

    return [$period, $source];
}

function uploadNoiCsv(Period $period, DataSource $source, User $user, string $csv): ReportUpload
{
    $storedPath = 'report_uploads/test_noi_' . uniqid() . '.csv';
    Storage::disk('public')->put($storedPath, $csv);

    return ReportUpload::query()->create([
        'period_id' => $period->id,
        'data_source_id' => $source->id,
        'original_name' => 'test_noi.csv',
        'stored_path' => $storedPath,
        'mime_type' => 'text/csv',
        'file_size' => Storage::disk('public')->size($storedPath),
        'uploaded_by' => $user->id,
        'uploaded_at' => now(),
        'status' => ReportUploadStatus::Pending,
        'notes' => null,
    ]);
}

// El bloque "Clave del trabajador : N" en columna B (posicional, no por nombre de
// encabezado — así viene el archivo NOI real) es lo único que activa blockEmployees[]
// y por lo tanto el ancla headcount_only. Columna A lleva el nombre.
it('does not create an employee for a NOI $0 block with no evidence outside NOI (stale baja)', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    [$period, $source] = makeNoiPeriodAndSource();

    $csv = <<<CSV
nombre,clave,concepto,tipo_concepto,importe,cantidad,tipo_nomina,fecha
Pedro Antiguo,"Clave del trabajador : 900",Sueldo,percepcion,0,1,ordinaria,2026-04-03
CSV;

    $upload = uploadNoiCsv($period, $source, $user, $csv);

    $run = app(ReportAnalysisService::class)->analyze($upload->fresh('dataSource'));

    expect($run->rows_inserted)->toBe(0);
    expect(Employee::query()->where('normalized_name', 'like', '%pedro antiguo%')->count())->toBe(0);
    expect(NoiMovement::query()->count())->toBe(0);
});

it('keeps a NOI $0 block when the person exists in the Lendus employee directory', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    [$period, $source] = makeNoiPeriodAndSource();

    LendusEmployeeDirectory::query()->create([
        'report_upload_id' => null,
        'codigo' => 'ORI001',
        'nombre' => 'Maria Gerente Vigente',
        'normalized_name' => 'maria gerente vigente',
        'puesto' => 'Gerente',
        'is_operational' => true,
    ]);

    $csv = <<<CSV
nombre,clave,concepto,tipo_concepto,importe,cantidad,tipo_nomina,fecha
Maria Gerente Vigente,"Clave del trabajador : 901",Sueldo,percepcion,0,1,ordinaria,2026-04-03
CSV;

    $upload = uploadNoiCsv($period, $source, $user, $csv);
    $run = app(ReportAnalysisService::class)->analyze($upload->fresh('dataSource'));

    expect($run->rows_inserted)->toBe(1); // fila ancla headcount_only
    expect(Employee::query()->where('normalized_name', 'maria gerente vigente')->exists())->toBeTrue();
    expect(NoiMovement::query()->where('concept_type', 'headcount_only')->count())->toBe(1);
});

it('evaluates evidence directly against Lendus directory, cartera and historical assignment', function () {
    // Sin ninguna fuente: sin evidencia.
    $resolver = app(\App\Services\PersonIdentityResolverService::class);
    expect($resolver->evaluateNoiCandidateEvidence('Nadie Conocido')['found'])->toBeFalse();

    // Directorio Lendus exacto.
    LendusEmployeeDirectory::query()->create([
        'nombre' => 'Carlos Subgerente', 'normalized_name' => 'carlos subgerente',
        'puesto' => 'Subgerente', 'is_operational' => true,
    ]);
    $ev = $resolver->evaluateNoiCandidateEvidence('Carlos Subgerente');
    expect($ev['found'])->toBeTrue();
    expect($ev['source'])->toBe('lendus_directory');
});

it('still inserts a NOI collaborator with real money even without Lendus evidence, but flags it for review', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    [$period, $source] = makeNoiPeriodAndSource();

    $csv = <<<CSV
codigo_empleado,nombre_empleado,concepto,tipo_concepto,importe,cantidad,tipo_nomina,fecha
EMP902,Juan Externo Sin Match,Sueldo,percepcion,15000,1,ordinaria,2026-04-03
CSV;

    $upload = uploadNoiCsv($period, $source, $user, $csv);

    // Llamada directa al importador (en vez de vía ReportAnalysisService) para poder
    // inspeccionar identity_stats/incidents sin depender de que el contenedor reutilice
    // la misma instancia entre llamadas.
    $import = app(\App\Services\Imports\NoiNominaImportService::class);
    $result = $import->handle($upload->fresh('dataSource'));

    expect($result['rows_inserted'])->toBe(1);
    expect(Employee::query()->where('normalized_name', 'juan externo sin match')->exists())->toBeTrue();
    expect($result['identity_stats']['needs_review'])->toBe(1);
    expect(collect($result['incidents'])->pluck('type')->all())->toContain('noi_identidad.sin_respaldo');
});
