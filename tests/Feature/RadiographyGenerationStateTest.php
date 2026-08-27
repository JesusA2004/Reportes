<?php

use App\Models\Period;
use App\Models\PeriodRadiographyRun;
use App\Models\PeriodSummary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * FASE 10 del cierre de Reportes (2026-08-26) — ORIGINALMENTE esta suite exigía
 * radiography_ready=false cuando el run más reciente fallaba después de que el
 * PeriodSummary ya se hubiera marcado 'generated' (para no confiar en un summary
 * potencialmente incompleto si el job murió antes de terminar branch-resolution/
 * consolidación). CAMBIO DELIBERADO (auditoría 27-ago-2026, Problema 2/4/5, a
 * pedido explícito del usuario — "VISTA PREVIA NO DEBE BLOQUEARSE PORQUE FALLÓ UN
 * EXPORT"): esa regla generaba EXACTAMENTE la contradicción reportada en
 * producción — el histórico (MonthlyReportController::index()/previewPage()/
 * exportRadiography(), que SOLO miran PeriodSummary.status, nunca el run) seguía
 * mostrando "Generado" con Ver/Excel/PDF funcionando para la misma identidad,
 * mientras el wizard (Etapa 6) decía "Bloqueado" — dos fuentes de verdad para la
 * misma pregunta. Ahora "radiography_ready" (Vista previa) depende SOLO del
 * summary (igual que el histórico); el estado del run (éxito/fallo) sigue
 * disponible por separado en radiography_run_status/radiography_run_error para
 * Etapa 5, y en PeriodRadiographyRun::resolveForIdentity()['latest_success'] para
 * decidir qué Excel/PDF ofrecer en Etapa 7 — nunca se vuelven a mezclar en una sola
 * bandera. Ver ReportUploadController::resolveWorkflowState().
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

it('reports radiography_ready=true (Vista previa disponible) even when the latest run failed, as long as the summary is generated', function () {
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
        'status' => 'failed',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'error_message' => 'No se pudo generar el PDF del reporte.',
    ]);

    $response = $this->actingAs($user)->get('/historico-general');

    $response->assertOk();
    $response->assertInertia(function ($page) use ($period) {
        $row = collect($page->toArray()['props']['periods'])->firstWhere('id', $period->id);
        expect($row)->not->toBeNull();
        // CASO 1 del pliego 27-ago-2026: "PeriodSummary generado + run antiguo failed
        // → preview DISPONIBLE." La Etapa 5 sigue mostrando 'failed' con su propio
        // detalle de error — eso es correcto y separado — pero ya no bloquea Vista
        // previa (Etapa 6), que se resuelve en vivo desde el summary, no desde archivos
        // ya escritos en disco.
        expect($row['radiography_ready'])->toBeTrue();
        expect($row['radiography_run_status'])->toBe('failed');
        expect($row['radiography_run_error'])->not->toBeNull();
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

it('still reports radiography_ready=true (but not has_previous_radiography) for a run that succeeded without writing both export files', function () {
    $user = User::factory()->create();
    $period = makeRadPeriodo();

    $summary = PeriodSummary::query()->create([
        'period_id' => $period->id,
        'status' => 'generated',
        'generated_at' => now(),
    ]);

    // Caso defensivo: status='success' pero sin output_pdf_path (bookkeeping incompleto,
    // ver GenerateRadiographyJob catch de "el Excel/PDF se generaron pero falló el registro").
    // Vista previa sigue disponible (depende solo del summary); PeriodRadiographyRun::
    // resolveForIdentity() exige AMBAS rutas para considerarlo un "latest_success" real,
    // así que esto NO habilita las descargas de Etapa 7 vía ese resolvedor específico de
    // identidad (los endpoints "planos" simple/general de MonthlyReportController siguen
    // funcionando porque regeneran en vivo desde el summary, no desde este run).
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
        expect($row['radiography_ready'])->toBeTrue();
        expect($row['has_previous_radiography'])->toBeFalse();
    });
});
