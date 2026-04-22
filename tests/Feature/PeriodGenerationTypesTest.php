<?php

use App\Models\Period;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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

it('creates bimonthly periods', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/periodos', [
            'type' => 'bimonthly',
            'year' => 2026,
            'month' => 4,
        ])
        ->assertRedirect();

    expect(Period::query()->where('type', 'bimonthly')->count())->toBe(2);
});

it('creates quarterly period', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/periodos', [
            'type' => 'quarterly',
            'year' => 2026,
            'month' => 4,
        ])
        ->assertRedirect();

    expect(Period::query()->where('type', 'quarterly')->count())->toBe(1);
});

it('creates semiannual period', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/periodos', [
            'type' => 'semiannual',
            'year' => 2026,
            'month' => 4,
        ])
        ->assertRedirect();

    expect(Period::query()->where('type', 'semiannual')->count())->toBe(1);
});

it('creates annual period', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/periodos', [
            'type' => 'annual',
            'year' => 2026,
            'month' => 4,
        ])
        ->assertRedirect();

    expect(Period::query()->where('type', 'annual')->count())->toBe(1);
});
