<?php

use Inertia\Testing\AssertableInertia as Assert;

/*
 * "Lupa password?" is only useful when the reset e-mail can actually be
 * delivered — with the `log`/`array` mailers it would be a dead end.
 */

test('login page hides the reset-password link while mail only goes to the log', function (string $mailer) {
    config(['mail.default' => $mailer]);

    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')
            ->where('canResetPassword', false));
})->with(['log', 'array']);

test('login page shows the reset-password link once a real mailer is configured', function (string $mailer) {
    config(['mail.default' => $mailer]);

    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')
            ->where('canResetPassword', true));
})->with(['smtp', 'ses']);

test('the reset-password routes keep working regardless of the mailer', function () {
    config(['mail.default' => 'log']);

    $this->get(route('password.request'))->assertOk();
});
