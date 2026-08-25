<?php

use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\Radiography\RadiographySnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Prueba de humo del testsuite RadiographyIntegration — demuestra específicamente lo
 * que SQLite NO puede: RadiographySnapshotBuilder::build() usa SQL propio de MySQL
 * (REGEXP, JSON_EXTRACT/JSON_UNQUOTE, ver los whereRaw() del propio archivo) que
 * SQLite rechaza con un error de sintaxis/función no encontrada incluso sobre una
 * tabla vacía. Corriendo contra MySQL/MariaDB real (conexión mysql_testing →
 * reportes_test, aislada de la BD de desarrollo), build() debe al menos EJECUTAR sin
 * reventar por sintaxis SQL — es la garantía que esta suite existe para dar.
 */
it('runs RadiographySnapshotBuilder::build() against real MySQL without SQL syntax errors', function () {
    $period = Period::query()->create([
        'name' => 'Semana Smoke Test', 'code' => 'W-SMOKE-01', 'type' => 'weekly',
        'year' => 2026, 'month' => 1, 'sequence' => 1,
        'start_date' => '2026-01-05', 'end_date' => '2026-01-09', 'is_closed' => false,
    ]);

    $summary = PeriodSummary::query()->create([
        'period_id' => $period->id, 'status' => 'generated', 'generated_at' => now(),
        'global_metrics' => [],
    ]);

    $snapshot = app(RadiographySnapshotBuilder::class)->build($period, $summary);

    expect($snapshot)->toBeArray();
    expect($snapshot)->toHaveKey('summary');
    expect($snapshot)->toHaveKey('sections');
});
