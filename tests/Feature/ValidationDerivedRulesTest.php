<?php

use App\Enums\MatchType;
use App\Enums\ReportUploadStatus;
use App\Models\DataSource;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Expense;
use App\Models\MonthlyEmployeeSummary;
use App\Models\NoiMovement;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows smart derived validations from noi expenses assignments and summaries', function () {
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
        'original_name' => 'test.csv',
        'stored_path' => 'report_uploads/test.csv',
        'mime_type' => 'text/csv',
        'file_size' => 123,
        'uploaded_by' => $user->id,
        'uploaded_at' => now(),
        'status' => ReportUploadStatus::Failed,
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

    EmployeeBranchAssignment::query()->create([
        'period_id' => $period->id,
        'employee_id' => $employee->id,
        'branch_id' => null,
        'source_type' => \App\Enums\SourceType::Lendus,
        'source_reference' => null,
        'match_type' => MatchType::Unmatched,
        'confidence' => 0,
        'was_manual_reviewed' => false,
        'notes' => 'Sin match',
    ]);

    Expense::query()->create([
        'period_id' => $period->id,
        'report_upload_id' => $upload->id,
        'employee_id' => null,
        'branch_id' => null,
        'category' => 'Viaticos',
        'concept' => 'Gasolina',
        'amount' => 50,
        'paid_amount' => 50,
        'expense_date' => '2026-04-03',
        'observations' => 'Carga local',
        'raw_payload' => ['ok' => true],
    ]);

    MonthlyEmployeeSummary::query()->create([
        'period_id' => $period->id,
        'employee_id' => $employee->id,
        'branch_id' => null,
        'total_payments' => 1000,
        'total_bonuses' => 0,
        'total_discounts' => 0,
        'total_expenses' => 0,
        'net_amount' => 1000,
        'has_useful_movement' => true,
        'included_in_report' => false,
        'exclusion_reason' => 'Sin sucursal asignada para el periodo.',
    ]);

    $this->actingAs($user)
        ->get('/validaciones')
        ->assertOk()
        ->assertSee('Empleado con NOI sin sucursal asignada')
        ->assertSee('Empleado sin match de sucursal')
        ->assertSee('Gasto sin empleado relacionado')
        ->assertSee('Gasto sin sucursal relacionada')
        ->assertSee('Archivo con error de procesamiento')
        ->assertSee('Empleado excluido del consolidado')
        ->assertSee('Periodo consolidado con empleados excluidos');
});
