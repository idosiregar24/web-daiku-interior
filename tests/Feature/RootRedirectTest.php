<?php

use App\Models\User;

/**
 * Sprint 20 D1 — `/` is the public company profile for guests; signed-in
 * users still go straight into the app. See routes/web.php.
 */
test('guests visiting the root see the company profile', function () {
    $this->get('/')->assertOk()->assertViewIs('site.home');
});

test('authenticated users visiting the root are redirected into the app', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')->assertRedirect(route('dashboard'));
});
