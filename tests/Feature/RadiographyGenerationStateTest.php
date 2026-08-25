<?php

use App\Models\Period;
use App\Models\PeriodRadiographyRun;
use App\Models\PeriodSummary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * FASE 10 del cierre de Reportes — prueba Feature del ENDPOINT REAL (no reflection,
 * no unit test interno) que reproduce el bug de julio: un run fallido después de que
 * el PeriodSummary ya se marcó 'generated' NO debe dejar radiography_ready=true en
 * Etapa 5 (/historico-general, ReportUploadController::index()). Ver
 * GenerateRadiographyJob::handle() / PeriodRadiographyService::generate() para la
 * causa raíz, y ReportUploadController::resolveWorkflowState() para el fix.
 */
function makeRadPeriodo(string $suffix = ''): Period
{
    return Period::query()->create([
        'name' => 'Julio 2026' . $suffix,
        'code' => 'M-2026-07' . $suffix,
        'type' => 'monthly',
        'year' => 2026,
        'month' => 7,
        'sequence' => 1,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'is_closed' => false,
    ]);
}

it('does not report radiography_ready=true when the latest run failed after the summary was marked generated', function () {
    $user = User::factory()->create();
    $period = makeRadPeriodo();

    $summary = PeriodSummary::query()->create([
        'period_id' => $period->id,
        'status' => 'generated', // el bug: esto se marca ANTES de que el job termine
        'generated_at' => now(),
    ]);

    PeriodRadiographyRun::query()->create([
        'period_id' => $period->id,
        'period_summary_id' => $summary->id,
        'report_type' => 'simple',
        'scope' => 'general',
        'status' => 'failed',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'error_message' => 'No se pudo generar la Radiografía. Revisa las fuentes cargadas, incidencias y configuración del reporte.',
    ]);

    $response = $this->actingAs($user)->get('/historico-general');

    $response->assertOk();
    $response->assertInertia(function ($page) use ($period) {
        $row = collect($page->toArray()['props']['periods'])->firstWhere('id', $period->id);
        expect($row)->not->toBeNull();
        // El bug real: antes de este fix, esto daba true (summary.status='generated' se
        // leía solo, ignorando que el run real había fallado).
        expect($row['radiography_ready'])->toBeFalse();
        expect($row['radiography_run_status'])->toBe('failed');
    });
});

it('reports radiography_ready=true only once the run actually succeeded with both files', function () {
    $user = User::factory()->create();
    $period = makeRadPeriodo();

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
        'started_at' => now()->subMinutes(2),
        'finished_at' => now(),
        'output_excel_path' => 'radiografias/test.xlsx',
        'output_pdf_path' => 'radiografias/test.pdf',
    ]);

    $response = $this->actingAs($user)->get('/historico-general');

    $response->assertOk();
    $response->assertInertia(function ($page) use ($period) {
        $row = collect($page->toArray()['props']['periods'])->firstWhere('id', $period->id);
        expect($row)->not->toBeNull();
        expect($row['radiography_ready'])->toBeTrue();
        expect($row['radiography_run_status'])->toBe('success');
    });
});

it('does not report radiography_ready=true for a run that succeeded without actually writing both export files', function () {
    $user = User::factory()->create();
    $period = makeRadPeriodo();

    $summary = PeriodSummary::query()->create([
        'period_id' => $period->id,
        'status' => 'generated',
        'generated_at' => now(),
    ]);

    // Caso defensivo: status='success' pero sin output_pdf_path (bookkeeping incompleto,
    // ver GenerateRadiographyJob catch de "el Excel/PDF se generaron pero falló el registro").
    PeriodRadiographyRun::query()->create([
        'period_id' => $period->id,
        'period_summary_id' => $summary->id,
        'report_type' => 'simple',
        'scope' => 'general',
        'status' => 'success',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'output_excel_path' => 'radiografias/test.xlsx',
        'output_pdf_path' => null,
    ]);

    $response = $this->actingAs($user)->get('/historico-general');

    $response->assertOk();
    $response->assertInertia(function ($page) use ($period) {
        $row = collect($page->toArray()['props']['periods'])->firstWhere('id', $period->id);
        expect($row['radiography_ready'])->toBeFalse();
    });
});
