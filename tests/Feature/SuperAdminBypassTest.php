<?php

use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Route;

/**
 * SUPERADMIN is a technical admin role (RoleSeeder) with unconditional
 * access to every `role:`-gated route via app/Http/Middleware/RoleMiddleware.
 */
beforeEach(fn () => $this->seed(RoleSeeder::class));

test('superadmin bypasses a role gate it does not literally hold', function () {
    Route::middleware(['web', 'auth', 'role:CEO'])
        ->get('/__test/ceo-only-bypass', fn () => 'ok');

    $superadmin = User::factory()->create();
    $superadmin->assignRole('SUPERADMIN');

    $this->actingAs($superadmin)->get('/__test/ceo-only-bypass')->assertOk();
});

test('a normal role is still blocked by role gates it does not hold', function () {
    Route::middleware(['web', 'auth', 'role:CEO'])
        ->get('/__test/ceo-only-bypass', fn () => 'ok');

    $marketing = User::factory()->create();
    $marketing->assignRole('MARKETING');

    $this->actingAs($marketing)->get('/__test/ceo-only-bypass')->assertForbidden();
});

test('superadmin has full access to CEO-only user management (god mode)', function () {
    $superadmin = User::factory()->create();
    $superadmin->assignRole('SUPERADMIN');

    $this->actingAs($superadmin)->get(route('users.index'))->assertOk();
});

test('superadmin has full access to CRM leads', function () {
    $superadmin = User::factory()->create();
    $superadmin->assignRole('SUPERADMIN');

    $this->actingAs($superadmin)->get(route('crm.leads.index'))->assertOk();
});

test('superadmin bypasses TaskPolicy via Gate::before, updating a task that is not their own', function () {
    $superadmin = User::factory()->create();
    $superadmin->assignRole('SUPERADMIN');
    $task = Task::factory()->create(['status' => 'PENDING']);

    $this->actingAs($superadmin)->patch(route('tasks.updateStatus', ['task' => $task->id]), [
        'status' => 'DONE',
    ])->assertRedirect();

    expect($task->fresh()->status->value)->toBe('DONE');
});
