<?php

use App\Models\User;

/**
 * `/` has no public marketing page (internal enterprise system) — it
 * just routes straight into the app. See routes/web.php.
 */
test('guests visiting the root are redirected to login', function () {
    $this->get('/')->assertRedirect(route('login'));
});

test('authenticated users visiting the root are redirected into the app', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')->assertRedirect(route('dashboard'));
});
