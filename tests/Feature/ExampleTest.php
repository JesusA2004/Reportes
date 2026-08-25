<?php

use App\Models\User;

/**
 * Reescrito 2026-08-25: el ExampleTest de scaffold asume que route('home') es una
 * página pública (sin autenticación) que responde 200 para cualquiera — cierto en un
 * Laravel nuevo, falso en este proyecto: no hay landing page pública, '/' siempre
 * redirige a /login (ver routes/web.php) y route('home')/'dashboard' viven dentro del
 * middleware auth+verified. Se ajusta al comportamiento real: invitado → redirect,
 * autenticado → 200.
 */
test('guests are redirected away from home', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('login'));
});

test('authenticated users get a successful response from home', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('home'));

    $response->assertOk();
});
