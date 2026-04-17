<?php

use App\Models\Period;
use App\Services\WeeklyPeriodGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates weekly periods according to the month-contained rule', function () {
    $service = app(WeeklyPeriodGeneratorService::class);

    $periods = $service->generateForMonth(2024, 11);

    expect($periods)->toHaveCount(5)
        ->and($periods->first()->start_date->toDateString())->toBe('2024-11-01')
        ->and($periods->first()->end_date->toDateString())->toBe('2024-11-03')
        ->and($periods[1]->start_date->toDateString())->toBe('2024-11-04')
        ->and($periods[1]->end_date->toDateString())->toBe('2024-11-10')
        ->and($periods->last()->start_date->toDateString())->toBe('2024-11-25')
        ->and($periods->last()->end_date->toDateString())->toBe('2024-11-30');

    expect(Period::query()->where('type', 'weekly')->count())->toBe(5);
});

it('creates or updates weekly periods from artisan command', function () {
    $this->artisan('periods:generate-weekly 2026 4')
        ->assertExitCode(0);

    expect(Period::query()->where('type', 'weekly')->where('year', 2026)->where('month', 4)->count())
        ->toBeGreaterThanOrEqual(4)
        ->toBeLessThanOrEqual(6);
});
