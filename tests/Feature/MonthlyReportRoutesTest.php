<?php

use App\Models\User;

it('loads reportes mensuales index without container resolution errors', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/reportes-mensuales')
        ->assertOk();
});
