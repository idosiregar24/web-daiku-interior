<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function accessUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

// PRD §7.1 "Project (overview)" R* + security-standards.md §2 (ProjectPolicy wajib).

test('a field staff member cannot open a project they have no task in', function () {
    $staff = accessUser('FIELD_STAFF');
    $otherProject = Project::factory()->create();

    $this->actingAs($staff)->get(route('projects.show', $otherProject))->assertForbidden();
});

test('a field staff member sees only their own tasks on their project', function () {
    $staff = accessUser('FIELD_STAFF');
    $project = Project::factory()->create();
    $own = Task::factory()->create(['project_id' => $project->id, 'assignee_id' => $staff->id]);
    Task::factory()->count(2)->create(['project_id' => $project->id]);

    $this->actingAs($staff)->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks', 1)
            ->where('tasks.0.id', $own->id)
            ->where('canViewTasks', true));
});

test('QA never receives task detail (PRD §4.6)', function () {
    $project = Project::factory()->create();
    Task::factory()->count(3)->create(['project_id' => $project->id]);

    $this->actingAs(accessUser('QA'))->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('tasks', 0)->where('canViewTasks', false));
});

test('roles outside the task rows of the matrix get no tasks', function (string $role) {
    $project = Project::factory()->create();
    Task::factory()->create(['project_id' => $project->id]);

    $this->actingAs(accessUser($role))->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('tasks', 0));
})->with(['MARKETING', 'DESIGNER', 'ESTIMATOR', 'FINANCE', 'LOGISTICS']);

test('CEO and PM see every task on the project', function (string $role) {
    $project = Project::factory()->create();
    Task::factory()->count(3)->create(['project_id' => $project->id]);

    $this->actingAs(accessUser($role))->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page->has('tasks', 3));
})->with(['CEO', 'PM']);
