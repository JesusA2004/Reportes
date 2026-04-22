<?php

use App\Enums\MatchType;
use App\Enums\ReportUploadStatus;
use App\Models\DataSource;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('prevents closing a period with critical validations', function () {
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

    ReportUpload::query()->create([
        'period_id' => $period->id,
        'data_source_id' => $source->id,
        'original_name' => 'bad.csv',
        'stored_path' => 'report_uploads/bad.csv',
        'mime_type' => 'text/csv',
        'file_size' => 120,
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

    $this->actingAs($user)
        ->post("/periodos/{$period->id}/close")
        ->assertRedirect();

    expect($period->fresh()->is_closed)->toBeFalse();
});
