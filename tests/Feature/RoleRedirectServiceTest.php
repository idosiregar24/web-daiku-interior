<?php

use App\Models\User;
use App\Services\RoleRedirectService;
use Database\Seeders\RoleSeeder;

beforeEach(fn () => $this->seed(RoleSeeder::class));

test('resolves the mapped route for a user with a seeded role', function () {
    $user = User::factory()->create();
    $user->assignRole('FINANCE');

    expect((new RoleRedirectService)->routeNameFor($user))->toBe('dashboard');
});

test('falls back to dashboard for a user with no role assigned', function () {
    $user = User::factory()->create();

    expect((new RoleRedirectService)->routeNameFor($user))->toBe('dashboard');
});
