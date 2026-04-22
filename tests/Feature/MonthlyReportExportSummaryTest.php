<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\MonthlyEmployeeSummary;
use App\Models\Period;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exports consolidated summary csv for a period', function () {
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

    MonthlyEmployeeSummary::query()->create([
        'period_id' => $period->id,
        'employee_id' => $employee->id,
        'branch_id' => $branch->id,
        'total_payments' => 1000,
        'total_bonuses' => 200,
        'total_discounts' => 100,
        'total_expenses' => 50,
        'net_amount' => 1050,
        'has_useful_movement' => true,
        'included_in_report' => true,
        'exclusion_reason' => null,
    ]);

    $response = $this->actingAs($user)
        ->get("/reportes-mensuales/{$period->id}/consolidado.csv");

    $response->assertOk();
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $content = $response->streamedContent();

    expect($content)->toContain('Juan Perez');
    expect($content)->toContain('Cuernavaca');
    expect($content)->toContain('period_code');
    expect($content)->toContain('W-2026-04-01');
});
