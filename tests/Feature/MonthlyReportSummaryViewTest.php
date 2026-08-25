<?php

use App\Models\Period;
use App\Models\PeriodRadiographyRun;
use App\Models\PeriodSummary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Reescrito 2026-08-25 — la vista `/reportes-mensuales` (MonthlyReportController::index())
 * dejó de listar filas de MonthlyEmployeeSummary directamente (ver commits "cambios
 * historico"/"cambios preview"): ahora es un listado de REPORTES REALMENTE GENERADOS,
 * uno por PeriodRadiographyRun con status='success' (identidad simple/comparativo/
 * sucursal/gestor), con fallback a PeriodSummary legacy sin run asociado. La versión
 * anterior de este test pegaba a esa misma URL pero esperaba ver "Juan Perez"/
 * "Cuernavaca" de un MonthlyEmployeeSummary suelto — quedó obsoleta por el rediseño,
 * no por una regresión real (ver MonthlyReportController::index()).
 */
it('lists a successfully generated radiography run in the monthly reports page', function () {
    $user = User::factory()->create();

    $period = Period::query()->create([
        'name' => 'Abril 2026',
        'code' => 'M-2026-04',
        'type' => 'monthly',
        'year' => 2026,
        'month' => 4,
        'sequence' => 1,
        'start_date' => '2026-04-01',
        'end_date' => '2026-04-30',
        'is_closed' => false,
    ]);

    $summary = PeriodSummary::query()->create([
        'period_id' => $period->id,
        'status' => 'generated',
        'generated_at' => now(),
    ]);

    PeriodRadiographyRun::query()->create([
        'period_id' => $period->id,
        'period_summary_id' => $summary->id,
        'report_type' => 'simple',
        'scope' => 'general',
        'status' => 'success',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'output_excel_path' => 'radiografias/test.xlsx',
        'output_pdf_path' => 'radiografias/test.pdf',
    ]);

    $this->actingAs($user)
        ->get('/reportes-mensuales')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ReportesMensuales/Index')
            ->has('generatedReports', 1)
            ->where('generatedReports.0.period', $period->label)
            ->where('generatedReports.0.status', 'generated')
        );
});

it('does not list a run whose generation failed', function () {
    $user = User::factory()->create();

    $period = Period::query()->create([
        'name' => 'Mayo 2026',
        'code' => 'M-2026-05',
        'type' => 'monthly',
        'year' => 2026,
        'month' => 5,
        'sequence' => 1,
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-31',
        'is_closed' => false,
    ]);

    PeriodRadiographyRun::query()->create([
        'period_id' => $period->id,
        'report_type' => 'simple',
        'scope' => 'general',
        'status' => 'failed',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'error_message' => 'No se pudo generar la Radiografía.',
    ]);

    $this->actingAs($user)
        ->get('/reportes-mensuales')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ReportesMensuales/Index')
            ->has('generatedReports', 0)
        );
});
