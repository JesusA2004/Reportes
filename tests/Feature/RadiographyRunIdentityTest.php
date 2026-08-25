<?php

use App\Models\Employee;
use App\Models\Period;
use App\Models\PeriodRadiographyRun;
use App\Models\PeriodSummary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * FASE 1 del pendiente 2026-08-25 — bug real identificado: "radiography_ready" y el
 * endpoint de progreso resolvían "el ÚLTIMO run del periodo, sin importar de qué
 * reporte era" (PeriodRadiographyRun::orderByDesc('id')->first()). Si se generaba un
 * GENERAL exitoso y DESPUÉS un reporte por sucursal/gestor (éxito o fallo) del MISMO
 * periodo, Etapa 5 del reporte GENERAL empezaba a mostrar el estado/errores del run
 * scoped, no el suyo. Corregido con PeriodRadiographyRun::scopeForIdentity() +
 * ReportUploadController::generationProgress() con parámetros de identidad.
 */
function makeIdentityPeriodo(): Period
{
    return Period::query()->create([
        'name' => 'Junio 2026', 'code' => 'M-2026-06', 'type' => 'monthly',
        'year' => 2026, 'month' => 6, 'sequence' => 1,
        'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'is_closed' => false,
    ]);
}

function makeIdentitySummary(Period $period): PeriodSummary
{
    return PeriodSummary::query()->create([
        'period_id' => $period->id, 'status' => 'generated', 'generated_at' => now(),
    ]);
}

it('keeps the GENERAL report ready after a later EMPLOYEE report succeeds', function () {
    $user = User::factory()->create();
    $period = makeIdentityPeriodo();
    $summary = makeIdentitySummary($period);
    $employee = Employee::query()->create([
        'employee_code' => 'EMP001', 'full_name' => 'Juan Perez', 'normalized_name' => 'juan perez',
        'first_name' => 'Juan', 'paternal_last_name' => 'Perez', 'is_active' => true, 'source_system' => 'noi',
    ]);

    $generalRun = PeriodRadiographyRun::query()->create([
        'period_id' => $period->id, 'period_summary_id' => $summary->id,
        'report_type' => 'simple', 'scope' => 'general', 'status' => 'success',
        'started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(9),
        'output_excel_path' => 'radiografias/general.xlsx', 'output_pdf_path' => 'radiografias/general.pdf',
    ]);

    // Generado DESPUÉS, para el MISMO periodo, con OTRO alcance.
    PeriodRadiographyRun::query()->create([
        'period_id' => $period->id, 'period_summary_id' => $summary->id,
        'report_type' => 'simple', 'scope' => 'employee', 'employee_id' => $employee->id, 'status' => 'success',
        'started_at' => now(), 'finished_at' => now(),
        'output_excel_path' => 'radiografias/empleado.xlsx', 'output_pdf_path' => 'radiografias/empleado.pdf',
    ]);

    $response = $this->actingAs($user)->get('/historico-general');
    $response->assertInertia(function ($page) use ($period) {
        $row = collect($page->toArray()['props']['periods'])->firstWhere('id', $period->id);
        expect($row['radiography_ready'])->toBeTrue();
        expect($row['radiography_run_status'])->toBe('success');
    });

    // El endpoint de progreso, pedido con identidad GENERAL, debe devolver el run
    // GENERAL — no "el último run del periodo" (que sería el de empleado).
    $progress = $this->actingAs($user)->getJson("/historico-general/{$period->id}/generar-reporte/progreso?report_type=simple&scope=general");
    $progress->assertOk()->assertJson(['run_id' => $generalRun->id, 'status' => 'success']);
});

it('keeps the GENERAL report ready after a later EMPLOYEE report fails', function () {
    $user = User::factory()->create();
    $period = makeIdentityPeriodo();
    $summary = makeIdentitySummary($period);
    $employee = Employee::query()->create([
        'employee_code' => 'EMP002', 'full_name' => 'Ana Lopez', 'normalized_name' => 'ana lopez',
        'first_name' => 'Ana', 'paternal_last_name' => 'Lopez', 'is_active' => true, 'source_system' => 'noi',
    ]);

    $generalRun = PeriodRadiographyRun::query()->create([
        'period_id' => $period->id, 'period_summary_id' => $summary->id,
        'report_type' => 'simple', 'scope' => 'general', 'status' => 'success',
        'started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(9),
        'output_excel_path' => 'radiografias/general.xlsx', 'output_pdf_path' => 'radiografias/general.pdf',
    ]);

    PeriodRadiographyRun::query()->create([
        'period_id' => $period->id, 'period_summary_id' => $summary->id,
        'report_type' => 'simple', 'scope' => 'employee', 'employee_id' => $employee->id, 'status' => 'failed',
        'started_at' => now(), 'finished_at' => now(), 'error_message' => 'Fallo simulado para empleado.',
    ]);

    $response = $this->actingAs($user)->get('/historico-general');
    $response->assertInertia(function ($page) use ($period) {
        $row = collect($page->toArray()['props']['periods'])->firstWhere('id', $period->id);
        // ESTE es exactamente el bug: antes, $runsByPeriod tomaba el último run (el
        // fallido de empleado) y radiography_ready/status del GENERAL se contaminaban.
        expect($row['radiography_ready'])->toBeTrue();
        expect($row['radiography_run_status'])->toBe('success');
    });

    $progress = $this->actingAs($user)->getJson("/historico-general/{$period->id}/generar-reporte/progreso?report_type=simple&scope=general");
    $progress->assertOk()->assertJson(['run_id' => $generalRun->id, 'status' => 'success']);

    $progressEmp = $this->actingAs($user)->getJson("/historico-general/{$period->id}/generar-reporte/progreso?report_type=simple&scope=employee&employee_id={$employee->id}");
    $progressEmp->assertOk()->assertJson(['status' => 'failed']);
});

it('does not let EMPLOYEE B failed affect EMPLOYEE A success (same period, different employees)', function () {
    $user = User::factory()->create();
    $period = makeIdentityPeriodo();
    $summary = makeIdentitySummary($period);

    $employeeA = Employee::query()->create([
        'employee_code' => 'EMPA', 'full_name' => 'Empleado A', 'normalized_name' => 'empleado a',
        'first_name' => 'Empleado', 'paternal_last_name' => 'A', 'is_active' => true, 'source_system' => 'noi',
    ]);
    $employeeB = Employee::query()->create([
        'employee_code' => 'EMPB', 'full_name' => 'Empleado B', 'normalized_name' => 'empleado b',
        'first_name' => 'Empleado', 'paternal_last_name' => 'B', 'is_active' => true, 'source_system' => 'noi',
    ]);

    $runA = PeriodRadiographyRun::query()->create([
        'period_id' => $period->id, 'period_summary_id' => $summary->id,
        'report_type' => 'simple', 'scope' => 'employee', 'employee_id' => $employeeA->id, 'status' => 'success',
        'started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(9),
        'output_excel_path' => 'radiografias/a.xlsx', 'output_pdf_path' => 'radiografias/a.pdf',
    ]);

    PeriodRadiographyRun::query()->create([
        'period_id' => $period->id, 'period_summary_id' => $summary->id,
        'report_type' => 'simple', 'scope' => 'employee', 'employee_id' => $employeeB->id, 'status' => 'failed',
        'started_at' => now(), 'finished_at' => now(), 'error_message' => 'Fallo simulado para B.',
    ]);

    $progressA = $this->actingAs($user)->getJson("/historico-general/{$period->id}/generar-reporte/progreso?report_type=simple&scope=employee&employee_id={$employeeA->id}");
    $progressA->assertOk()->assertJson(['run_id' => $runA->id, 'status' => 'success']);

    $progressB = $this->actingAs($user)->getJson("/historico-general/{$period->id}/generar-reporte/progreso?report_type=simple&scope=employee&employee_id={$employeeB->id}");
    $progressB->assertOk()->assertJson(['status' => 'failed']);
});

it('gives each BRANCH its own run/exports when two branches were generated for the same period', function () {
    $user = User::factory()->create();
    $period = makeIdentityPeriodo();
    $summary = makeIdentitySummary($period);

    $branchA = \App\Models\Branch::query()->create(['code' => 'CUER', 'name' => 'Cuernavaca', 'normalized_name' => 'cuernavaca', 'is_active' => true]);
    $branchB = \App\Models\Branch::query()->create(['code' => 'TULA', 'name' => 'Tula', 'normalized_name' => 'tula', 'is_active' => true]);

    $runA = PeriodRadiographyRun::query()->create([
        'period_id' => $period->id, 'period_summary_id' => $summary->id,
        'report_type' => 'simple', 'scope' => 'branch', 'branch_id' => $branchA->id, 'status' => 'success',
        'started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(9),
        'output_excel_path' => 'radiografias/branch_a.xlsx', 'output_pdf_path' => 'radiografias/branch_a.pdf',
    ]);
    $runB = PeriodRadiographyRun::query()->create([
        'period_id' => $period->id, 'period_summary_id' => $summary->id,
        'report_type' => 'simple', 'scope' => 'branch', 'branch_id' => $branchB->id, 'status' => 'success',
        'started_at' => now(), 'finished_at' => now(),
        'output_excel_path' => 'radiografias/branch_b.xlsx', 'output_pdf_path' => 'radiografias/branch_b.pdf',
    ]);

    $progressA = $this->actingAs($user)->getJson("/historico-general/{$period->id}/generar-reporte/progreso?report_type=simple&scope=branch&branch_id={$branchA->id}");
    $progressA->assertOk()->assertJson(['run_id' => $runA->id, 'status' => 'success']);
    expect($progressA->json('excel_url'))->toContain((string) $runA->id);

    $progressB = $this->actingAs($user)->getJson("/historico-general/{$period->id}/generar-reporte/progreso?report_type=simple&scope=branch&branch_id={$branchB->id}");
    $progressB->assertOk()->assertJson(['run_id' => $runB->id, 'status' => 'success']);
    expect($progressB->json('excel_url'))->toContain((string) $runB->id);
});
