<?php

use App\Models\Period;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Reescrito 2026-08-25: bimonthly/quarterly/semiannual/annual dejaron de crearse
 * directamente desde el request — PeriodGenerationService::generate() para esos tipos
 * SOLO llama a syncDerivedPeriods($year), que los DERIVA automáticamente a partir de
 * los periodos mensuales que YA EXISTEN (ver syncGroupedPeriod(): "Solo se genera si
 * TODOS los meses operativos del bloque existen"). El parámetro 'month' del request no
 * aplica a estos tipos. La versión anterior de este archivo asumía la API vieja
 * (creación directa por tipo+mes) y por eso fallaba con count()==0 — no era una
 * regresión del motor, sino un test desactualizado por el cambio de arquitectura.
 */
function makeMonthlyPeriod(int $year, int $month): Period
{
    return Period::query()->create([
        'name' => "Mes {$month} - {$year}",
        'code' => sprintf('M-%04d-%02d', $year, $month),
        'type' => 'monthly',
        'year' => $year,
        'month' => $month,
        'sequence' => 1,
        'start_date' => sprintf('%04d-%02d-01', $year, $month),
        'end_date' => date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $month))),
        'is_closed' => false,
    ]);
}

it('creates weekly periods', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/periodos', [
            'type' => 'weekly',
            'year' => 2026,
            'month' => 4,
        ])
        ->assertRedirect();

    expect(Period::query()->where('type', 'weekly')->count())->toBeGreaterThanOrEqual(4);
});

it('derives a bimonthly period once both its component months exist', function () {
    $user = User::factory()->create();

    // Abril cae en el bloque bimestral 2 (marzo+abril) — ver
    // PeriodGenerationService::syncBimonthlyPeriods().
    makeMonthlyPeriod(2026, 3);
    makeMonthlyPeriod(2026, 4);

    $this->actingAs($user)
        ->post('/periodos', ['type' => 'bimonthly', 'year' => 2026, 'month' => 4])
        ->assertRedirect();

    expect(Period::query()->where('type', 'bimonthly')->where('year', 2026)->count())->toBe(1);
});

it('derives a quarterly period once all three component months exist', function () {
    $user = User::factory()->create();

    // Abril cae en el trimestre 2 (abril+mayo+junio).
    makeMonthlyPeriod(2026, 4);
    makeMonthlyPeriod(2026, 5);
    makeMonthlyPeriod(2026, 6);

    $this->actingAs($user)
        ->post('/periodos', ['type' => 'quarterly', 'year' => 2026, 'month' => 4])
        ->assertRedirect();

    expect(Period::query()->where('type', 'quarterly')->where('year', 2026)->count())->toBe(1);
});

it('derives a semiannual period once all six component months exist', function () {
    $user = User::factory()->create();

    foreach (range(1, 6) as $month) {
        makeMonthlyPeriod(2026, $month);
    }

    $this->actingAs($user)
        ->post('/periodos', ['type' => 'semiannual', 'year' => 2026, 'month' => 1])
        ->assertRedirect();

    expect(Period::query()->where('type', 'semiannual')->where('year', 2026)->count())->toBe(1);
});

it('derives an annual period once all twelve component months exist', function () {
    $user = User::factory()->create();

    foreach (range(1, 12) as $month) {
        makeMonthlyPeriod(2026, $month);
    }

    $this->actingAs($user)
        ->post('/periodos', ['type' => 'annual', 'year' => 2026, 'month' => 1])
        ->assertRedirect();

    expect(Period::query()->where('type', 'annual')->where('year', 2026)->count())->toBe(1);
});

it('does not derive a bimonthly period when only one of its two component months exists', function () {
    $user = User::factory()->create();

    makeMonthlyPeriod(2026, 4); // falta marzo del mismo bloque

    $this->actingAs($user)
        ->post('/periodos', ['type' => 'bimonthly', 'year' => 2026, 'month' => 4])
        ->assertRedirect();

    expect(Period::query()->where('type', 'bimonthly')->where('year', 2026)->count())->toBe(0);
});
