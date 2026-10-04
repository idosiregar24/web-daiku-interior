<?php

use App\Http\Middleware\ModuleAccessMiddleware;
use App\Models\Employee;
use App\Models\User;
use App\Services\RoleRedirectService;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function sdmUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

// ── SDM dashboard (HR's landing page) ───────────────────────────────────

test('HR lands on the SDM dashboard after login', function () {
    expect(app(RoleRedirectService::class)->routeNameFor(sdmUser('HR')))->toBe('hr.dashboard');
});

test('CEO and HR open the SDM dashboard, other roles are refused', function () {
    Employee::factory()->count(2)->create();
    Employee::factory()->inactive()->create();

    foreach (['CEO', 'HR'] as $role) {
        $this->actingAs(sdmUser($role))->get(route('hr.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Dashboard')
                ->where('headcount.active', 2)
                ->where('headcount.inactive', 1)
                ->has('discipline')
                ->has('salary')
                ->has('kpi')
                ->has('reviews'));
    }

    foreach (['FINANCE', 'PM', 'MARKETING', 'FIELD_STAFF'] as $role) {
        $this->actingAs(sdmUser($role))->get(route('hr.dashboard'))->assertForbidden();
    }
});

test('the module gate is one place: every hr.* route sits behind module:hr', function () {
    $hrRoutes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'hr.'));

    expect($hrRoutes)->not->toBeEmpty();
    $hrRoutes->each(fn ($route) => expect($route->gatherMiddleware())->toContain('module:hr'));
    expect(ModuleAccessMiddleware::MODULE_ROLES['hr'])->toBe(['CEO', 'HR']);
});

// ── "Kinerja Saya" (decision #6 / #11) ──────────────────────────────────

test('an employee with a linked account opens their own page', function () {
    $user = sdmUser('DESIGNER');
    $employee = Employee::factory()->create(['user_id' => $user->id]);
    Employee::factory()->create(); // someone else

    $this->actingAs($user)->get(route('my.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('HR/My/Index')
            ->where('employee.id', $employee->id)
            ->has('kpi')
            ->has('reviews')
            ->has('discipline')
            ->has('salary')
            ->where('auth.user.has_employee', true));
});

test('users without an active employee row cannot open the page', function () {
    $unlinked = sdmUser('MARKETING');
    $this->actingAs($unlinked)->get(route('my.index'))->assertForbidden();

    $inactive = sdmUser('QA');
    Employee::factory()->inactive()->create(['user_id' => $inactive->id]);
    $this->actingAs($inactive)->get(route('my.index'))->assertForbidden();
});

test('field staff never reach the page, even with a stale employee link', function () {
    $tukang = sdmUser('FIELD_STAFF');
    Employee::factory()->create(['user_id' => $tukang->id]);

    $this->actingAs($tukang)->get(route('my.index'))->assertForbidden();
    $this->actingAs($tukang)->get(route('tasks.index'))->assertOk(); // their own module still works
});

test('the shared auth prop tells the nav whether to show the menu', function () {
    $linked = sdmUser('ESTIMATOR');
    Employee::factory()->create(['user_id' => $linked->id]);

    $this->actingAs($linked)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.has_employee', true));
    $this->actingAs(sdmUser('ESTIMATOR'))->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.has_employee', false));
});
