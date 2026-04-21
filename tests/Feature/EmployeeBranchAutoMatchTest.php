<?php

use App\Enums\ReportUploadStatus;
use App\Models\Branch;
use App\Models\DataSource;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Models\User;
use App\Services\EmployeeBranchAutoMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates automatic employee branch assignments from expenses', function () {
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

    $upload = ReportUpload::query()->create([
        'period_id' => $period->id,
        'data_source_id' => $source->id,
        'original_name' => 'test_gastos.csv',
        'stored_path' => 'report_uploads/test_gastos.csv',
        'mime_type' => 'text/csv',
        'file_size' => 123,
        'uploaded_by' => $user->id,
        'uploaded_at' => now(),
        'status' => ReportUploadStatus::Processed,
        'notes' => null,
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

    Expense::query()->create([
        'period_id' => $period->id,
        'report_upload_id' => $upload->id,
        'employee_id' => $employee->id,
        'branch_id' => $branch->id,
        'category' => 'Viaticos',
        'concept' => 'Gasolina',
        'amount' => 500,
        'paid_amount' => 500,
        'expense_date' => '2026-04-03',
        'observations' => 'Carga local',
        'raw_payload' => ['ok' => true],
    ]);

    $result = app(EmployeeBranchAutoMatchService::class)->handle($period->id);

    expect($result['processed'])->toBe(1);
    expect($result['matched'])->toBe(1);
    expect($result['unmatched'])->toBe(0);

    $assignment = \App\Models\EmployeeBranchAssignment::query()->first();

    expect($assignment)->not->toBeNull();
    expect($assignment->period_id)->toBe($period->id);
    expect($assignment->employee_id)->toBe($employee->id);
    expect($assignment->branch_id)->toBe($branch->id);
    expect($assignment->match_type)->toBe(\App\Enums\MatchType::Normalized);
    expect((float) $assignment->confidence)->toBe(0.8);
});
