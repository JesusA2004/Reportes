<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows executing employee branch auto match route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/asignaciones-empleado-sucursal/match-automatico', [])
        ->assertRedirect();
});
