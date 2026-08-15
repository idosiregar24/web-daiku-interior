<?php

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

test('PM can view the task index', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');

    $this->actingAs($pm)->get(route('tasks.index'))->assertOk();
});

test('field staff task index is scoped to their own tasks', function () {
    $staff = User::factory()->create();
    $staff->assignRole('FIELD_STAFF');

    $ownTask = Task::factory()->create(['assignee_id' => $staff->id]);
    $otherTask = Task::factory()->create();

    $this->actingAs($staff)->get(route('tasks.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1)
            ->where('tasks.data.0.id', $ownTask->id)
        );

    expect($otherTask)->not->toBeNull();
});

test('roles without access are forbidden from the task index', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('tasks.index'))->assertForbidden();
})->with(['MARKETING', 'DESIGNER', 'ESTIMATOR', 'QA', 'FINANCE', 'LOGISTICS']);

test('PM can assign a task to a field staff', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $staff = User::factory()->create();
    $staff->assignRole('FIELD_STAFF');
    $project = Project::factory()->create();

    $response = $this->actingAs($pm)->post(route('tasks.store', ['project' => $project->id]), [
        'title' => 'Pasang kusen',
        'assignee_id' => $staff->id,
        'due_date' => now()->addWeek()->toDateString(),
        'priority' => 'HIGH',
        'rate_per_task' => 150_000,
    ]);

    $response->assertRedirect();

    $task = Task::where('title', 'Pasang kusen')->first();
    expect($task)->not->toBeNull()
        ->and($task->assignee_id)->toBe($staff->id)
        ->and($task->created_by)->toBe($pm->id)
        ->and($task->is_locked)->toBeTrue()
        ->and($task->status)->toBe(TaskStatus::Pending);
});

test('roles other than PM cannot assign a task', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');
    $staff = User::factory()->create();
    $staff->assignRole('FIELD_STAFF');
    $project = Project::factory()->create();

    $this->actingAs($ceo)->post(route('tasks.store', ['project' => $project->id]), [
        'title' => 'Pasang kusen',
        'assignee_id' => $staff->id,
        'due_date' => now()->addWeek()->toDateString(),
    ])->assertForbidden();
});

test('field staff can update the status of their own task', function () {
    $staff = User::factory()->create();
    $staff->assignRole('FIELD_STAFF');
    $task = Task::factory()->create(['assignee_id' => $staff->id, 'status' => TaskStatus::Pending->value]);

    $this->actingAs($staff)->patch(route('tasks.updateStatus', ['task' => $task->id]), [
        'status' => 'ONPROGRESS',
        'kendala' => 'Material belum sampai',
    ])->assertRedirect();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::OnProgress)
        ->and($task->kendala)->toBe('Material belum sampai');
});

test('field staff cannot update the status of another tukang\'s task', function () {
    $staff = User::factory()->create();
    $staff->assignRole('FIELD_STAFF');
    $task = Task::factory()->create(['status' => TaskStatus::Pending->value]);

    $this->actingAs($staff)->patch(route('tasks.updateStatus', ['task' => $task->id]), [
        'status' => 'ONPROGRESS',
    ])->assertForbidden();
});

test('PM can update the status of any task', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $task = Task::factory()->create(['status' => TaskStatus::Pending->value]);

    $this->actingAs($pm)->patch(route('tasks.updateStatus', ['task' => $task->id]), [
        'status' => 'DONE',
    ])->assertRedirect();

    expect($task->fresh()->status)->toBe(TaskStatus::Done)
        ->and($task->fresh()->completed_at)->not->toBeNull();
});

test('task status update rejects OVER as a manual value', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $task = Task::factory()->create(['status' => TaskStatus::Pending->value]);

    $this->actingAs($pm)->patch(route('tasks.updateStatus', ['task' => $task->id]), [
        'status' => 'OVER',
    ])->assertSessionHasErrors('status');
});

test('task service sets completed_at only when moving to DONE', function () {
    $task = Task::factory()->create(['status' => TaskStatus::Pending->value]);
    $actor = User::factory()->create();

    $updated = app(TaskService::class)->updateStatus($task, ['status' => 'ONPROGRESS'], $actor);
    expect($updated->completed_at)->toBeNull();

    $updated = app(TaskService::class)->updateStatus($task, ['status' => 'DONE'], $actor);
    expect($updated->completed_at)->not->toBeNull();
});
