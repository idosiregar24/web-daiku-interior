<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(fn () => $this->seed(RoleSeeder::class));

/**
 * Sprint 9 decision #6 — Designer, Estimator, PM and QA land on their
 * division dashboard (RoleRedirectService) both after login and via `/`.
 */
test('division roles land on their own dashboard after login and from the root', function (string $role, string $routeName) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route($routeName, absolute: false));

    $this->actingAs($user)->get('/')->assertRedirect(route($routeName));
    $this->actingAs($user)->get(route($routeName))->assertOk();
})->with([
    ['DESIGNER', 'design.dashboard'],
    ['ESTIMATOR', 'quotations.dashboard'],
    ['PM', 'projects.dashboard'],
    ['QA', 'qa-forms.dashboard'],
]);
