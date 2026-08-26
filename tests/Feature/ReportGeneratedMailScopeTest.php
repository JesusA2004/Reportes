<?php

use App\Mail\ReportGeneratedMail;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Period;
use App\Models\PeriodRadiographyRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Problema 3 (cierre del flujo de generación, 2026-08-26): el correo de éxito
 * siempre decía "Reporte listo para descargar" armado desde el periodo genérico
 * (tipo de periodo, ej. "Mes operativo"), nunca reflejaba QUÉ radiografía se
 * generó realmente. Ahora ReportGeneratedMail arma asunto/cuerpo desde la
 * IDENTIDAD real del PeriodRadiographyRun que terminó (report_type/scope/
 * branch_id/employee_id/comparison_period_id) — ver
 * ReportGeneratedMail::buildContext().
 */
function mailPeriodo(string $code, int $month = 6): Period
{
    return Period::query()->create([
        'name' => "Periodo mail {$code}", 'code' => $code, 'type' => 'monthly',
        'year' => 2026, 'month' => $month, 'sequence' => 1,
        'start_date' => sprintf('2026-%02d-01', $month), 'end_date' => sprintf('2026-%02d-28', $month), 'is_closed' => false,
    ]);
}

it('builds a GENERAL subject/body without branch or employee details', function () {
    $user   = User::factory()->create();
    $period = mailPeriodo('M-MAIL-GEN');
    $run = PeriodRadiographyRun::query()->create([
        'period_id' => $period->id, 'report_type' => 'simple', 'scope' => 'general', 'status' => 'success',
        'started_at' => now()->subMinute(), 'finished_at' => now(),
        'output_excel_path' => 'x.xlsx', 'output_pdf_path' => 'x.pdf',
    ]);

    $mail = new ReportGeneratedMail($period, $user, $run, 'https://example.test/preview');

    expect($mail->envelope()->subject)->toBe('Radiografía general lista — ' . $period->label);
    $html = $mail->render();
    expect($html)->toContain('General — todas las sucursales')
        ->toContain($period->label);
});

it('builds a BRANCH subject/body with the branch name', function () {
    $user   = User::factory()->create();
    $period = mailPeriodo('M-MAIL-BR');
    $branch = Branch::query()->create(['code' => 'ORZ', 'name' => 'ORIZABA', 'is_active' => true]);
    $run = PeriodRadiographyRun::query()->create([
        'period_id' => $period->id, 'report_type' => 'simple', 'scope' => 'branch', 'branch_id' => $branch->id,
        'status' => 'success', 'started_at' => now()->subMinute(), 'finished_at' => now(),
        'output_excel_path' => 'x.xlsx', 'output_pdf_path' => 'x.pdf',
    ]);

    $mail = new ReportGeneratedMail($period, $user, $run, 'https://example.test/preview');

    expect($mail->envelope()->subject)->toBe('Radiografía de sucursal lista — ' . $period->label . ' — ORIZABA');
    $html = $mail->render();
    expect($html)->toContain('ORIZABA')->toContain('Sucursal');
});

it('builds an EMPLOYEE subject/body with the employee name and historic branch', function () {
    $user     = User::factory()->create();
    $period   = mailPeriodo('M-MAIL-EMP');
    $branch   = Branch::query()->create(['code' => 'ORZ2', 'name' => 'ORIZABA', 'is_active' => true]);
    $employee = Employee::query()->create([
        'employee_code' => 'EMPMAIL', 'full_name' => 'MARGARITA JAZMIN NOLASCO DOMINGUEZ',
        'normalized_name' => 'margarita jazmin nolasco dominguez',
        'first_name' => 'Margarita', 'paternal_last_name' => 'Nolasco', 'is_active' => true, 'source_system' => 'noi',
    ]);
    EmployeeBranchAssignment::query()->create([
        'period_id' => $period->id, 'employee_id' => $employee->id, 'branch_id' => $branch->id,
    ]);
    $run = PeriodRadiographyRun::query()->create([
        'period_id' => $period->id, 'report_type' => 'simple', 'scope' => 'employee', 'employee_id' => $employee->id,
        'status' => 'success', 'started_at' => now()->subMinute(), 'finished_at' => now(),
        'output_excel_path' => 'x.xlsx', 'output_pdf_path' => 'x.pdf',
    ]);

    $mail = new ReportGeneratedMail($period, $user, $run, 'https://example.test/preview');

    expect($mail->envelope()->subject)->toBe('Radiografía de gestor lista — ' . $period->label . ' — MARGARITA JAZMIN NOLASCO DOMINGUEZ');
    $html = $mail->render();
    expect($html)->toContain('MARGARITA JAZMIN NOLASCO DOMINGUEZ')
        ->toContain('Empleado / Gestor')
        ->toContain('ORIZABA'); // sucursal histórica
});

it('builds a COMPARATIVE subject/body showing both periods', function () {
    $user  = User::factory()->create();
    $juneP = mailPeriodo('M-MAIL-JUN', 6);
    $mayP  = mailPeriodo('M-MAIL-MAY', 5);
    $run = PeriodRadiographyRun::query()->create([
        'period_id' => $juneP->id, 'report_type' => 'month_vs_month', 'scope' => 'general',
        'comparison_period_id' => $mayP->id, 'status' => 'success',
        'started_at' => now()->subMinute(), 'finished_at' => now(),
        'output_excel_path' => 'x.xlsx', 'output_pdf_path' => 'x.pdf',
    ]);

    $mail = new ReportGeneratedMail($juneP, $user, $run, 'https://example.test/preview');

    expect($mail->envelope()->subject)->toBe('Comparativo listo — ' . $juneP->label . ' vs ' . $mayP->label);
    $html = $mail->render();
    expect($html)->toContain($juneP->label)->toContain($mayP->label)->toContain('Comparativo mes vs mes');
});
