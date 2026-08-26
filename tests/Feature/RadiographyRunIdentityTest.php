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
    static $seq = 0;
    $seq++;
    // (type, year, month, sequence) es único — cada llamada usa un mes distinto
    // (1-12) para no chocar, sin importar cuántos periodos necesite un mismo test.
    $month = (($seq - 1) % 12) + 1;

    return Period::query()->create([
        'name' => "Periodo test {$seq}", 'code' => "M-2026-{$month}-{$seq}", 'type' => 'monthly',
        'year' => 2026, 'month' => $month, 'sequence' => 1,
        'start_date' => sprintf('2026-%02d-01', $month), 'end_date' => sprintf('2026-%02d-28', $month), 'is_closed' => false,
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

/**
 * Problema 4 (cierre del flujo de generación, 2026-08-26): "Exportación" y
 * "Generar reporte" mostraban "En proceso" para el reporte GENERAL ya
 * completado solo porque OTRO alcance (empleado) estaba corriendo AHORA MISMO
 * para el mismo periodo — radiography_running/can_export_radiography usaban un
 * flag period-wide ($running, que incluye cualquier alcance) en vez de uno
 * propio de la identidad. Ver ReportUploadController::resolveWorkflowState().
 */
it('keeps GENERAL export/running state unaffected while an EMPLOYEE run is actively queued for the same period', function () {
    $user = User::factory()->create();
    $period = makeIdentityPeriodo();
    $summary = makeIdentitySummary($period);
    $employee = Employee::query()->create([
        'employee_code' => 'EMP003', 'full_name' => 'Carlos Ruiz', 'normalized_name' => 'carlos ruiz',
        'first_name' => 'Carlos', 'paternal_last_name' => 'Ruiz', 'is_active' => true, 'source_system' => 'noi',
    ]);

    PeriodRadiographyRun::query()->create([
        'period_id' => $period->id, 'period_summary_id' => $summary->id,
        'report_type' => 'simple', 'scope' => 'general', 'status' => 'success',
        'started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(9),
        'output_excel_path' => 'radiografias/general.xlsx', 'output_pdf_path' => 'radiografias/general.pdf',
    ]);

    // Un reporte de OTRO alcance está corriendo AHORA MISMO para el mismo periodo.
    PeriodRadiographyRun::query()->create([
        'period_id' => $period->id, 'period_summary_id' => $summary->id,
        'report_type' => 'simple', 'scope' => 'employee', 'employee_id' => $employee->id, 'status' => 'queued',
        'queued_at' => now(),
    ]);

    $response = $this->actingAs($user)->get('/historico-general');
    $response->assertInertia(function ($page) use ($period) {
        $row = collect($page->toArray()['props']['periods'])->firstWhere('id', $period->id);

        // El GENERAL ya terminó — su propio estado visual (Etapa 6/7) no debe
        // decir "en proceso" solo porque otro alcance esté corriendo.
        expect($row['radiography_ready'])->toBeTrue();
        expect($row['radiography_running'])->toBeFalse();
        expect($row['can_export_radiography'])->toBeTrue();

        // El doble-submit SÍ debe seguir bloqueado mientras cualquier alcance corre.
        expect($row['can_generate_radiography'])->toBeFalse();
        expect($row['blocking_reasons'])->toContain('La Radiografía está en proceso.');
        // Pero el mensaje "en proceso" no debe colarse en el listado de UI para Etapa 5.
        expect($row['blocking_reasons_display'] ?? [])->not->toContain('La Radiografía está en proceso.');
    });
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

it('takes the NEW success run over an old failed run of the exact same identity', function () {
    $user = User::factory()->create();
    $period = makeIdentityPeriodo();
    $summary = makeIdentitySummary($period);
    $employee = Employee::query()->create([
        'employee_code' => 'EMP017', 'full_name' => 'Colaborador Retry', 'normalized_name' => 'colaborador retry',
        'first_name' => 'Colaborador', 'paternal_last_name' => 'Retry', 'is_active' => true, 'source_system' => 'noi',
    ]);

    PeriodRadiographyRun::query()->create([
        'period_id' => $period->id, 'period_summary_id' => $summary->id,
        'report_type' => 'simple', 'scope' => 'employee', 'employee_id' => $employee->id, 'status' => 'failed',
        'started_at' => now()->subDays(19), 'finished_at' => now()->subDays(19),
        'error_message' => 'Intento viejo fallido.',
    ]);

    $newRun = PeriodRadiographyRun::query()->create([
        'period_id' => $period->id, 'period_summary_id' => $summary->id,
        'report_type' => 'simple', 'scope' => 'employee', 'employee_id' => $employee->id, 'status' => 'success',
        'started_at' => now(), 'finished_at' => now(),
        'output_excel_path' => 'radiografias/retry.xlsx', 'output_pdf_path' => 'radiografias/retry.pdf',
    ]);

    $progress = $this->actingAs($user)->getJson("/historico-general/{$period->id}/generar-reporte/progreso?report_type=simple&scope=employee&employee_id={$employee->id}");
    $progress->assertOk()->assertJson(['run_id' => $newRun->id, 'status' => 'success', 'error_message' => null]);
});

it('keeps two comparative runs (different comparison_period_id) from contaminating each other', function () {
    $user = User::factory()->create();
    $period = makeIdentityPeriodo();
    $summary = makeIdentitySummary($period);
    $comparePeriodA = makeIdentityPeriodo();
    $comparePeriodB = makeIdentityPeriodo();

    $runCmpA = PeriodRadiographyRun::query()->create([
        'period_id' => $period->id, 'period_summary_id' => $summary->id,
        'report_type' => 'month_vs_month', 'scope' => 'general', 'comparison_period_id' => $comparePeriodA->id,
        'status' => 'success', 'started_at' => now(), 'finished_at' => now(),
        'output_excel_path' => 'radiografias/cmp_a.xlsx', 'output_pdf_path' => 'radiografias/cmp_a.pdf',
    ]);
    PeriodRadiographyRun::query()->create([
        'period_id' => $period->id, 'period_summary_id' => $summary->id,
        'report_type' => 'month_vs_month', 'scope' => 'general', 'comparison_period_id' => $comparePeriodB->id,
        'status' => 'failed', 'started_at' => now(), 'finished_at' => now(), 'error_message' => 'Fallo simulado B.',
    ]);

    $progressA = $this->actingAs($user)->getJson("/historico-general/{$period->id}/generar-reporte/progreso?report_type=month_vs_month&scope=general&compare_period_id={$comparePeriodA->id}");
    $progressA->assertOk()->assertJson(['run_id' => $runCmpA->id, 'status' => 'success']);

    $progressB = $this->actingAs($user)->getJson("/historico-general/{$period->id}/generar-reporte/progreso?report_type=month_vs_month&scope=general&compare_period_id={$comparePeriodB->id}");
    $progressB->assertOk()->assertJson(['status' => 'failed']);
});

it('reproduces the exact production fixture (julio 2026): GENERAL failed + comparative failed must not shadow the EMPLOYEE success run', function () {
    $user = User::factory()->create();
    $period = makeIdentityPeriodo();
    $summary = makeIdentitySummary($period);
    $employee = Employee::query()->create([
        'employee_code' => 'EMP017', 'full_name' => 'Empleado Diecisiete', 'normalized_name' => 'empleado diecisiete',
        'first_name' => 'Empleado', 'paternal_last_name' => 'Diecisiete', 'is_active' => true, 'source_system' => 'noi',
    ]);
    $comparePeriod = makeIdentityPeriodo();

    // RUN 12 (producción real): simple/general, failed, hace semanas.
    PeriodRadiographyRun::query()->create([
        'period_id' => $period->id, 'period_summary_id' => $summary->id,
        'report_type' => 'simple', 'scope' => 'general', 'status' => 'failed',
        'started_at' => '2026-08-06 11:59:38', 'finished_at' => '2026-08-06 11:59:50',
        'error_message' => 'No se pudo generar la Radiografía.',
    ]);

    // RUN 17 (producción real): month_vs_month/general/comparison=X, failed.
    PeriodRadiographyRun::query()->create([
        'period_id' => $period->id, 'period_summary_id' => $summary->id,
        'report_type' => 'month_vs_month', 'scope' => 'general', 'comparison_period_id' => $comparePeriod->id,
        'status' => 'failed', 'started_at' => now()->subDay(), 'finished_at' => now()->subDay(),
        'error_message' => 'Fallo comparativo simulado.',
    ]);

    // RUN 22 (producción real): simple/employee/employee_id=17, success.
    $run22 = PeriodRadiographyRun::query()->create([
        'period_id' => $period->id, 'period_summary_id' => $summary->id,
        'report_type' => 'simple', 'scope' => 'employee', 'employee_id' => $employee->id, 'status' => 'success',
        'started_at' => '2026-08-25 14:37:40', 'finished_at' => '2026-08-25 14:37:49',
        'output_excel_path' => 'radiografias/gestor_17.xlsx', 'output_pdf_path' => 'radiografias/gestor_17.pdf',
    ]);

    // La config activa del wizard (Etapa 4): simple / employee / employee_id=17.
    $progress = $this->actingAs($user)->getJson(
        "/historico-general/{$period->id}/generar-reporte/progreso?report_type=simple&scope=employee&employee_id={$employee->id}"
    );

    $progress->assertOk()->assertJson([
        'run_id'        => $run22->id,
        'status'        => 'success',
        'error_message' => null,
    ]);
    expect($progress->json('excel_url'))->not->toBeNull();
    expect($progress->json('pdf_url'))->not->toBeNull();
});
