<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('a user saves the sidebar groups they folded', function () {
    $user = User::factory()->create();
    $user->assignRole('FINANCE');

    $this->actingAs($user)
        ->patchJson(route('profile.nav-preferences.update'), ['collapsed_groups' => ['Presales', 'SDM']])
        ->assertNoContent();

    expect($user->fresh()->nav_preferences)->toBe(['collapsed_groups' => ['Presales', 'SDM']]);
});

test('the folded groups are shared with every page', function () {
    $user = User::factory()->create(['nav_preferences' => ['collapsed_groups' => ['Logistik']]]);
    $user->assignRole('PM');

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page->where('auth.user.nav_preferences.collapsed_groups', ['Logistik']));
});

test('an empty list unfolds every group', function () {
    $user = User::factory()->create(['nav_preferences' => ['collapsed_groups' => ['SDM']]]);
    $user->assignRole('CEO');

    $this->actingAs($user)
        ->patchJson(route('profile.nav-preferences.update'), ['collapsed_groups' => []])
        ->assertNoContent();

    expect($user->fresh()->nav_preferences['collapsed_groups'])->toBe([]);
});

test('unknown group labels are rejected', function () {
    $user = User::factory()->create();
    $user->assignRole('CEO');

    $this->actingAs($user)
        ->patchJson(route('profile.nav-preferences.update'), ['collapsed_groups' => ['Presales', '<script>']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('collapsed_groups.1');

    $this->actingAs($user)
        ->patchJson(route('profile.nav-preferences.update'), ['collapsed_groups' => 'SDM'])
        ->assertJsonValidationErrors('collapsed_groups');

    expect($user->fresh()->nav_preferences)->toBeNull();
});

test('guests are sent to login', function () {
    $this->patch(route('profile.nav-preferences.update'), ['collapsed_groups' => []])
        ->assertRedirect(route('login'));
});
