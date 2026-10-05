<?php

use App\Models\Project;
use App\Models\Quotation;
use App\Models\User;
use App\Services\RoleRedirectService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function sprint12User(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole(User::rolesFor($role));

    return $user;
}

// ── Seeding ──────────────────────────────────────────────────────────────

test('the role seeder creates the two Sprint 12 roles', function () {
    expect(Role::whereIn('name', ['ASISTEN_PM', 'KEPALA_DESAIN'])->count())->toBe(2);
});

test('the demo seed has an Asisten PM and a Kepala Desain who is a Designer too', function () {
    $this->seed(DatabaseSeeder::class);

    $assistant = User::where('email', 'asistenpm@daikuinterior.com')->sole();
    $head = User::where('email', 'kepaladesain@daikuinterior.com')->sole();

    expect($assistant->getRoleNames()->all())->toBe(['ASISTEN_PM'])
        ->and($head->hasAllRoles(['DESIGNER', 'KEPALA_DESAIN']))->toBeTrue();
});

// ── Kepala Desain: stacked on DESIGNER ───────────────────────────────────

test('a Kepala Desain is a Designer everywhere: landing page, shared role, designer-only routes', function () {
    $head = sprint12User('KEPALA_DESAIN');

    expect($head->primaryRoleName())->toBe('DESIGNER')
        ->and($head->assignableRoleName())->toBe('KEPALA_DESAIN')
        ->and((new RoleRedirectService)->routeNameFor($head))->toBe('design.dashboard');

    $this->actingAs($head)->get(route('design.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.role', 'DESIGNER')
            ->where('auth.user.display_role', 'KEPALA_DESAIN')
            ->where('auth.user.roles', fn ($roles) => collect($roles)->sort()->values()->all() === ['DESIGNER', 'KEPALA_DESAIN']));
});

test('User Management assigns Kepala Desain together with Designer, and shows it as Kepala Desain', function () {
    $ceo = sprint12User('CEO');

    $this->actingAs($ceo)->post(route('users.store'), [
        'name' => 'Rina Arsitek',
        'email' => 'rina@daiku.test',
        'password' => 'password-kuat-123',
        'password_confirmation' => 'password-kuat-123',
        'role' => 'KEPALA_DESAIN',
    ])->assertSessionHasNoErrors();

    $user = User::where('email', 'rina@daiku.test')->sole();
    expect($user->hasAllRoles(['DESIGNER', 'KEPALA_DESAIN']))->toBeTrue();

    $this->actingAs($ceo)->get(route('users.edit', $user))
        ->assertInertia(fn (Assert $page) => $page->where('user.assignable_role', 'KEPALA_DESAIN'));

    // Back to a plain Designer drops the stacked role.
    $this->actingAs($ceo)->put(route('users.update', $user), [
        'name' => 'Rina Arsitek', 'email' => 'rina@daiku.test', 'role' => 'DESIGNER', 'is_active' => true,
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->getRoleNames()->all())->toBe(['DESIGNER']);
});

// ── Asisten PM: read what the PM reads, write nothing yet ───────────────

test('an Asisten PM can open the project and quotation pages a PM reads', function (string $routeName) {
    $project = Project::factory()->create();
    $quotation = Quotation::factory()->create();
    $params = match ($routeName) {
        'projects.show' => $project,
        'quotations.show' => $quotation,
        default => [],
    };

    $this->actingAs(sprint12User('ASISTEN_PM'))->get(route($routeName, $params))->assertOk();
})->with(['projects.index', 'projects.dashboard', 'projects.show', 'quotations.index', 'quotations.show']);

test('the Asisten PM sees the project tabs the PM reads, without any write action', function () {
    $project = Project::factory()->create();

    $this->actingAs(sprint12User('ASISTEN_PM'))->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canViewMilestones', true)
            ->where('canViewTasks', true)
            ->where('canViewProgressLogs', true)
            ->where('canViewTermins', true)
            ->where('canViewMaterials', true)
            ->where('canManageMilestones', false)
            ->where('canManageTasks', false)
            ->where('canCreateTermins', false)
            ->where('canEditProject', false));
});

test('an Asisten PM is refused every PM write action for now', function () {
    $assistant = sprint12User('ASISTEN_PM');
    $project = Project::factory()->create(['pm_id' => $assistant->id]);

    $this->actingAs($assistant)->post(route('projects.store'), [])->assertForbidden();
    $this->actingAs($assistant)->put(route('projects.update', $project), [])->assertForbidden();
    $this->actingAs($assistant)->post(route('milestones.store', $project), [])->assertForbidden();
    $this->actingAs($assistant)->post(route('tasks.store', $project), [])->assertForbidden();
    $this->actingAs($assistant)->post(route('projects.materials.store', $project), [])->assertForbidden();
});
