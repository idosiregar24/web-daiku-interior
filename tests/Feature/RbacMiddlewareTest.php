<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Route;

/**
 * Proves the Spatie `role` middleware alias registered in bootstrap/app.php
 * actually resolves and enforces access — see .claude/rules/backend-standards.md
 * §3 for the route-group pattern this protects. No PRD module route exists
 * yet to test against directly, so this defines a throwaway route inline.
 */
beforeEach(fn () => $this->seed(RoleSeeder::class));

test('role middleware alias allows a user with the required role', function () {
    Route::middleware(['web', 'auth', 'role:CEO'])
        ->get('/__test/ceo-only', fn () => 'ok');

    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');

    $this->actingAs($ceo)->get('/__test/ceo-only')->assertOk();
});

test('role middleware alias blocks a user without the required role', function () {
    Route::middleware(['web', 'auth', 'role:CEO'])
        ->get('/__test/ceo-only', fn () => 'ok');

    $marketing = User::factory()->create();
    $marketing->assignRole('MARKETING');

    $this->actingAs($marketing)->get('/__test/ceo-only')->assertForbidden();
});

test('role middleware alias blocks a guest entirely', function () {
    Route::middleware(['web', 'auth', 'role:CEO'])
        ->get('/__test/ceo-only', fn () => 'ok');

    $this->get('/__test/ceo-only')->assertRedirect('/login');
});
