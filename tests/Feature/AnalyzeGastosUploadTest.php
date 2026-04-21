<?php

use App\Enums\ReportUploadStatus;
use App\Models\Branch;
use App\Models\DataSource;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Models\User;
use App\Services\ReportAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('analyzes a gastos upload and creates expense records', function () {
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
        'code' => 'gastos',
        'name' => 'Gastos',
        'description' => 'Importación de gastos',
        'is_active' => true,
    ]);

    $employee = Employee::query()->create([
        'employee_code' => 'EMP001',
        'full_name' => 'Juan Perez',
        'normalized_name' => 'juan perez',
        'first_name' => 'Juan',
        'paternal_last_name' => 'Perez',
        'maternal_last_name' => null,
        'is_active' => true,
        'source_system' => 'noi',
    ]);

    $branch = Branch::query()->create([
        'code' => 'CUER',
        'name' => 'Cuernavaca',
        'normalized_name' => 'cuernavaca',
        'is_active' => true,
    ]);

    $csv = <<<CSV
empleado,sucursal,categoria,concepto,importe,monto_pagado,fecha_gasto,observaciones
Juan Perez,Cuernavaca,Viaticos,Gasolina,500,500,2026-04-03,Carga local
CSV;

    $storedPath = 'report_uploads/test_gastos.csv';
    Storage::disk('public')->put($storedPath, $csv);

    $upload = ReportUpload::query()->create([
        'period_id' => $period->id,
        'data_source_id' => $source->id,
        'original_name' => 'test_gastos.csv',
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

    expect($run->rows_read)->toBe(1);
    expect($run->rows_inserted)->toBe(1);
    expect($run->rows_with_errors)->toBe(0);

    expect(Expense::query()->count())->toBe(1);

    $expense = Expense::query()->first();
    expect($expense->employee_id)->toBe($employee->id);
    expect($expense->branch_id)->toBe($branch->id);
    expect((float) $expense->amount)->toBe(500.0);
});
