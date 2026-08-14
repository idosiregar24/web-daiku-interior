<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(fn () => $this->seed(RoleSeeder::class));

test('CEO can view the user management index', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');

    $this->actingAs($ceo)->get(route('users.index'))->assertOk();
});

test('non-CEO roles are forbidden from user management', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('users.index'))->assertForbidden();
})->with(['MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'QA', 'FINANCE', 'LOGISTICS', 'FIELD_STAFF']);

test('CEO can create a user and assign a role', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');

    $response = $this->actingAs($ceo)->post(route('users.store'), [
        'name' => 'Jonathan Sigalingging',
        'email' => 'jonathan@daikuinterior.com',
        'password' => 'password123',
        'role' => 'PM',
    ]);

    $response->assertRedirect(route('users.index'));

    $newUser = User::where('email', 'jonathan@daikuinterior.com')->first();
    expect($newUser)->not->toBeNull()
        ->and($newUser->hasRole('PM'))->toBeTrue();
});

test('CEO can update a user role', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');

    $target = User::factory()->create();
    $target->assignRole('MARKETING');

    $this->actingAs($ceo)->put(route('users.update', $target), [
        'name' => $target->name,
        'email' => $target->email,
        'role' => 'FINANCE',
        'is_active' => true,
    ])->assertRedirect(route('users.index'));

    $target->refresh();
    expect($target->hasRole('FINANCE'))->toBeTrue()
        ->and($target->hasRole('MARKETING'))->toBeFalse();
});
