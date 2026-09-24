<?php

use App\Models\User;
use App\Services\RoleRedirectService;
use Database\Seeders\RoleSeeder;

beforeEach(fn () => $this->seed(RoleSeeder::class));

test('resolves each role to its PRD §8.4 landing page', function (string $role, string $routeName) {
    $user = User::factory()->create();
    $user->assignRole($role);

    expect((new RoleRedirectService)->routeNameFor($user))->toBe($routeName);
})->with([
    ['CEO', 'analytics.index'],
    ['MARKETING', 'crm.dashboard'],
    ['FINANCE', 'finance.dashboard'],
    ['LOGISTICS', 'logistics.materials.index'],
    ['FIELD_STAFF', 'tasks.index'],
    ['PM', 'dashboard'],
    ['SUPERADMIN', 'master-data.index'],
]);

test('every mapped landing route is actually reachable by that role', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route((new RoleRedirectService)->routeNameFor($user)))->assertOk();
})->with(['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'QA', 'FINANCE', 'LOGISTICS', 'FIELD_STAFF', 'SUPERADMIN']);

test('falls back to dashboard for a user with no role assigned', function () {
    $user = User::factory()->create();

    expect((new RoleRedirectService)->routeNameFor($user))->toBe('dashboard');
});
