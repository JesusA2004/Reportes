<?php

use App\Models\Period;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows closing a clean period', function () {
    $user = User::factory()->create();

    $period = Period::query()->create([
        'name' => 'Semana 1 - Abril 2026',
        'code' => 'W-2026-04-01',
        'type' => 'weekly',
        'year' => 2026,
        'month' => 4,
        'sequence' => 1,
        'start_date' => '2026-04-01',
        'end_date' => '2026-04-05',
        'is_closed' => false,
    ]);

    $this->actingAs($user)
        ->post("/periodos/{$period->id}/close")
        ->assertRedirect();

    expect($period->fresh()->is_closed)->toBeTrue();
});
