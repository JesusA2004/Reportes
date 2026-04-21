<?php

use App\Enums\MatchType;
use App\Enums\ReportUploadStatus;
use App\Models\Branch;
use App\Models\DataSource;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Expense;
use App\Models\MonthlyEmployeeSummary;
use App\Models\NoiMovement;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Models\User;
use App\Services\PeriodConsolidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('consolidates a period into monthly employee summaries', function () {
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

    $upload = ReportUpload::query()->create([
        'period_id' => $period->id,
        'data_source_id' => $source->id,
        'original_name' => 'test_consolidation.csv',
        'stored_path' => 'report_uploads/test_consolidation.csv',
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

    EmployeeBranchAssignment::query()->create([
        'period_id' => $period->id,
        'employee_id' => $employee->id,
        'branch_id' => $branch->id,
        'source_type' => \App\Enums\SourceType::Lendus,
        'source_reference' => 'fact_expenses',
        'match_type' => MatchType::Normalized,
        'confidence' => 0.8,
        'was_manual_reviewed' => false,
        'notes' => null,
    ]);

    NoiMovement::query()->create([
        'period_id' => $period->id,
        'employee_id' => $employee->id,
        'report_upload_id' => $upload->id,
        'concept' => 'Sueldo',
        'concept_type' => 'percepcion',
        'amount' => 1000,
        'quantity' => 1,
        'payroll_type' => 'ordinaria',
        'movement_date' => '2026-04-03',
        'raw_row_hash' => 'hash-1',
        'raw_payload' => ['ok' => true],
    ]);

    NoiMovement::query()->create([
        'period_id' => $period->id,
        'employee_id' => $employee->id,
        'report_upload_id' => $upload->id,
        'concept' => 'Bono productividad',
        'concept_type' => 'percepcion',
        'amount' => 200,
        'quantity' => 1,
        'payroll_type' => 'ordinaria',
        'movement_date' => '2026-04-03',
        'raw_row_hash' => 'hash-2',
        'raw_payload' => ['ok' => true],
    ]);

    NoiMovement::query()->create([
        'period_id' => $period->id,
        'employee_id' => $employee->id,
        'report_upload_id' => $upload->id,
        'concept' => 'Descuento',
        'concept_type' => 'deduccion',
        'amount' => 100,
        'quantity' => 1,
        'payroll_type' => 'ordinaria',
        'movement_date' => '2026-04-03',
        'raw_row_hash' => 'hash-3',
        'raw_payload' => ['ok' => true],
    ]);

    Expense::query()->create([
        'period_id' => $period->id,
        'report_upload_id' => $upload->id,
        'employee_id' => $employee->id,
        'branch_id' => $branch->id,
        'category' => 'Viaticos',
        'concept' => 'Gasolina',
        'amount' => 50,
        'paid_amount' => 50,
        'expense_date' => '2026-04-03',
        'observations' => 'Carga local',
        'raw_payload' => ['ok' => true],
    ]);

    $result = app(PeriodConsolidationService::class)->consolidate($period);

    expect($result['created'])->toBe(1);
    expect($result['included'])->toBe(1);
    expect($result['excluded'])->toBe(0);

    $summary = MonthlyEmployeeSummary::query()->first();

    expect($summary)->not->toBeNull();
    expect((float) $summary->total_payments)->toBe(1000.0);
    expect((float) $summary->total_bonuses)->toBe(200.0);
    expect((float) $summary->total_discounts)->toBe(100.0);
    expect((float) $summary->total_expenses)->toBe(50.0);
    expect((float) $summary->net_amount)->toBe(1050.0);
    expect($summary->included_in_report)->toBeTrue();
});
