<?php

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\DailyTaskForm;
use App\Models\FinanceTransaction;
use App\Models\Milestone;
use App\Models\Notification;
use App\Models\OvertimeRequest;
use App\Models\Penalty;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

afterEach(fn () => Carbon::setTestNow());

function taskUpdateUser(string $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole($role);

    return $user;
}

/** A task on an ACTIVE project, assigned to a real (active) Field Staff. */
function editableTask(array $attributes = []): Task
{
    return Task::factory()->create([
        'assignee_id' => taskUpdateUser('FIELD_STAFF')->id,
        'due_date' => now()->addWeek()->toDateString(),
        'status' => TaskStatus::Pending->value,
        'rate_per_task' => 150_000,
        'milestone_id' => null,
        ...$attributes,
    ]);
}

/** The edit form's payload for `$task` as it is, with `$overrides`. */
function taskUpdatePayload(Task $task, array $overrides = []): array
{
    return [
        'title' => $task->title,
        'description' => $task->description,
        'due_date' => $task->due_date->toDateString(),
        'priority' => $task->priority->value,
        'milestone_id' => $task->milestone_id,
        'assignee_id' => $task->assignee_id,
        'rate_per_task' => $task->rate_per_task === null ? null : (float) $task->rate_per_task,
        ...$overrides,
    ];
}

function markWagePaid(Task $task): void
{
    FinanceTransaction::factory()->create([
        'type' => FinanceTransactionType::Expense->value,
        'kategori' => FinanceCategory::GajiKaryawan->value,
        'reference_id' => $task->id,
        'amount' => $task->rate_per_task,
    ]);
}

// ── RBAC (PRD §7.1 "Task – Create/Edit": PM CRUD; golden rule #6) ───────

test('a PM can edit any task, including on another PM\'s project', function () {
    $task = editableTask();

    $this->actingAs(taskUpdateUser('PM'))
        ->put(route('tasks.update', $task), taskUpdatePayload($task, [
            'title' => 'Pasang kusen lantai 2',
            'description' => 'Kusen jati',
            'priority' => 'HIGH',
            'due_date' => now()->addDays(10)->toDateString(),
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $task->refresh();
    expect($task->title)->toBe('Pasang kusen lantai 2')
        ->and($task->description)->toBe('Kusen jati')
        ->and($task->priority->value)->toBe('HIGH')
        ->and($task->due_date->toDateString())->toBe(now()->addDays(10)->toDateString());
});

test('field staff get 403 editing or deleting a task — even their own', function () {
    $staff = taskUpdateUser('FIELD_STAFF');
    $task = editableTask(['assignee_id' => $staff->id]);

    $this->actingAs($staff)
        ->put(route('tasks.update', $task), taskUpdatePayload($task, ['title' => 'Diubah tukang', 'due_date' => now()->addMonth()->toDateString()]))
        ->assertForbidden();

    $this->actingAs($staff)->delete(route('tasks.destroy', $task))->assertForbidden();

    expect($task->fresh()->title)->not->toBe('Diubah tukang')
        ->and(Task::find($task->id))->not->toBeNull();
});

test('roles other than PM cannot edit or delete tasks', function (string $role) {
    $task = editableTask();
    $user = taskUpdateUser($role);

    $this->actingAs($user)->put(route('tasks.update', $task), taskUpdatePayload($task, ['title' => 'X']))->assertForbidden();
    $this->actingAs($user)->delete(route('tasks.destroy', $task))->assertForbidden();
})->with(['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'QA', 'FINANCE', 'LOGISTICS']);

test('SUPERADMIN bypasses the PM gate for task edits', function () {
    $task = editableTask();

    $this->actingAs(taskUpdateUser('SUPERADMIN'))
        ->put(route('tasks.update', $task), taskUpdatePayload($task, ['title' => 'Oleh admin']))
        ->assertSessionHasNoErrors();

    expect($task->fresh()->title)->toBe('Oleh admin');
});

// ── Assignee / milestone rules ───────────────────────────────────────────

test('re-assigning notifies the new tukang like creation does and is audited', function () {
    $pm = taskUpdateUser('PM');
    $previous = taskUpdateUser('FIELD_STAFF', ['name' => 'Andi']);
    $next = taskUpdateUser('FIELD_STAFF', ['name' => 'Joko']);
    $task = editableTask(['assignee_id' => $previous->id]);

    $this->actingAs($pm)->put(route('tasks.update', $task), taskUpdatePayload($task, ['assignee_id' => $next->id]))
        ->assertSessionHasNoErrors();

    $notification = Notification::where('user_id', $next->id)->where('type', 'task_assigned')->sole();
    expect($task->fresh()->assignee_id)->toBe($next->id)
        ->and($notification->metadata)->toMatchArray(['task_id' => $task->id, 'project_id' => $task->project_id])
        ->and(Notification::where('user_id', $previous->id)->exists())->toBeFalse();

    $log = AuditLog::where('action', 'task.updated')->sole();
    expect($log->user_id)->toBe($pm->id)
        ->and($log->old_values)->toBe(['assignee' => 'Andi'])
        ->and($log->new_values)->toBe(['assignee' => 'Joko']);
});

test('a rate change is audited, a plain title change is not', function () {
    $pm = taskUpdateUser('PM');
    $task = editableTask();

    $this->actingAs($pm)->put(route('tasks.update', $task), taskUpdatePayload($task, ['title' => 'Judul baru']))
        ->assertSessionHasNoErrors();
    expect(AuditLog::where('action', 'task.updated')->exists())->toBeFalse();

    $this->actingAs($pm)->put(route('tasks.update', $task), taskUpdatePayload($task->fresh(), ['rate_per_task' => 200_000]))
        ->assertSessionHasNoErrors();

    $log = AuditLog::where('action', 'task.updated')->sole();
    expect($log->old_values)->toBe(['rate_per_task' => '150000.00'])
        ->and($log->new_values)->toBe(['rate_per_task' => '200000.00']);
});

test('a new assignee must be an active field staff member', function (Closure $makeUser) {
    $task = editableTask();

    $this->actingAs(taskUpdateUser('PM'))
        ->put(route('tasks.update', $task), taskUpdatePayload($task, ['assignee_id' => $makeUser()->id]))
        ->assertSessionHasErrors('assignee_id');
})->with([
    'deactivated tukang' => fn () => taskUpdateUser('FIELD_STAFF', ['is_active' => false]),
    'not a tukang' => fn () => taskUpdateUser('LOGISTICS'),
]);

test('a deactivated tukang may keep the task they already hold', function () {
    $staff = taskUpdateUser('FIELD_STAFF');
    $task = editableTask(['assignee_id' => $staff->id]);
    $staff->update(['is_active' => false]);

    $this->actingAs(taskUpdateUser('PM'))
        ->put(route('tasks.update', $task), taskUpdatePayload($task, ['title' => 'Masih milik tukang lama']))
        ->assertSessionHasNoErrors();
});

test('a task can only move to a milestone of its own project that has not passed QA', function () {
    $pm = taskUpdateUser('PM');
    $task = editableTask();
    $ownOpen = Milestone::factory()->create(['project_id' => $task->project_id]);
    $ownCompleted = Milestone::factory()->create(['project_id' => $task->project_id, 'status' => MilestoneStatus::Completed->value]);
    $foreign = Milestone::factory()->create();

    $this->actingAs($pm)->put(route('tasks.update', $task), taskUpdatePayload($task, ['milestone_id' => $foreign->id]))
        ->assertSessionHasErrors('milestone_id');
    $this->actingAs($pm)->put(route('tasks.update', $task), taskUpdatePayload($task, ['milestone_id' => $ownCompleted->id]))
        ->assertSessionHasErrors('milestone_id');
    $this->actingAs($pm)->put(route('tasks.update', $task), taskUpdatePayload($task, ['milestone_id' => $ownOpen->id]))
        ->assertSessionHasNoErrors();

    expect($task->fresh()->milestone_id)->toBe($ownOpen->id);
});

test('a task already inside a completed milestone keeps it while other fields change', function () {
    $task = editableTask();
    $completed = Milestone::factory()->create(['project_id' => $task->project_id, 'status' => MilestoneStatus::Completed->value]);
    $task->update(['milestone_id' => $completed->id]);

    $this->actingAs(taskUpdateUser('PM'))
        ->put(route('tasks.update', $task), taskUpdatePayload($task->fresh(), ['title' => 'Revisi judul']))
        ->assertSessionHasNoErrors();
});

test('creating a task also requires a milestone of the same project and an active tukang', function () {
    $pm = taskUpdateUser('PM');
    $project = Project::factory()->create();
    $payload = [
        'title' => 'Pasang kusen',
        'assignee_id' => taskUpdateUser('FIELD_STAFF')->id,
        'due_date' => now()->addWeek()->toDateString(),
    ];

    $this->actingAs($pm)->post(route('tasks.store', $project), [...$payload, 'milestone_id' => Milestone::factory()->create()->id])
        ->assertSessionHasErrors('milestone_id');
    $this->actingAs($pm)->post(route('tasks.store', $project), [...$payload, 'assignee_id' => taskUpdateUser('FIELD_STAFF', ['is_active' => false])->id])
        ->assertSessionHasErrors('assignee_id');
    $this->actingAs($pm)->post(route('tasks.store', $project), [...$payload, 'assignee_id' => taskUpdateUser('QA')->id])
        ->assertSessionHasErrors('assignee_id');

    expect($project->tasks()->count())->toBe(0);
});

// ── DONE / paid lock ─────────────────────────────────────────────────────

test('a DONE task not yet paid only lets title, description and rate change', function () {
    $pm = taskUpdateUser('PM');
    $task = editableTask(['status' => TaskStatus::Done->value, 'completed_at' => now()]);

    $this->actingAs($pm)->put(route('tasks.update', $task), taskUpdatePayload($task, ['title' => 'Judul final', 'rate_per_task' => 175_000]))
        ->assertSessionHasNoErrors();
    expect($task->fresh()->title)->toBe('Judul final')
        ->and((float) $task->fresh()->rate_per_task)->toBe(175_000.0);

    $this->actingAs($pm)->put(route('tasks.update', $task), taskUpdatePayload($task->fresh(), [
        'due_date' => now()->addMonth()->toDateString(),
        'priority' => 'LOW',
    ]))->assertSessionHasErrors(['due_date', 'priority']);

    $this->actingAs($pm)->put(route('tasks.update', $task), taskUpdatePayload($task->fresh(), ['assignee_id' => taskUpdateUser('FIELD_STAFF')->id]))
        ->assertSessionHasErrors('assignee_id');

    expect($task->fresh()->priority->value)->toBe('MEDIUM');
});

test('a DONE task whose wage is paid is fully locked', function () {
    $pm = taskUpdateUser('PM');
    $task = editableTask(['status' => TaskStatus::Done->value, 'completed_at' => now()]);
    markWagePaid($task);

    $this->actingAs($pm)->put(route('tasks.update', $task), taskUpdatePayload($task, ['title' => 'Coba ubah', 'rate_per_task' => 999_000]))
        ->assertSessionHasErrors('task');

    expect($task->fresh()->title)->not->toBe('Coba ubah')
        ->and((float) $task->fresh()->rate_per_task)->toBe(150_000.0);

    $this->actingAs($pm)->get(route('tasks.index'))
        ->assertInertia(fn (Assert $page) => $page->where('tasks.data.0.is_wage_paid', true));
});

test('tasks of a closed project cannot be edited or deleted', function (ProjectStatus $status) {
    $pm = taskUpdateUser('PM');
    $task = editableTask(['project_id' => Project::factory()->create(['status' => $status->value])->id]);

    $this->actingAs($pm)->put(route('tasks.update', $task), taskUpdatePayload($task, ['title' => 'Terlambat']))
        ->assertSessionHasErrors('project');
    $this->actingAs($pm)->delete(route('tasks.destroy', $task))
        ->assertSessionHasErrors('project');

    expect(Task::find($task->id)?->title)->toBe($task->title);
})->with([ProjectStatus::Completed, ProjectStatus::Cancelled]);

// ── OVER restore (tasks.pre_overdue_status) ──────────────────────────────

test('the overdue job remembers the status a task had before OVER', function () {
    $pengecekan = editableTask(['due_date' => now()->subDays(2)->toDateString(), 'status' => TaskStatus::Pengecekan->value]);
    $pending = editableTask(['due_date' => now()->subDays(2)->toDateString(), 'status' => TaskStatus::Pending->value]);

    expect(app(TaskService::class)->markOverdueTasks())->toBe(2)
        ->and($pengecekan->fresh()->status)->toBe(TaskStatus::Over)
        ->and($pengecekan->fresh()->pre_overdue_status)->toBe(TaskStatus::Pengecekan)
        ->and($pending->fresh()->pre_overdue_status)->toBe(TaskStatus::Pending);
});

test('pushing an OVER task\'s deadline to today or later restores its previous status', function () {
    $pm = taskUpdateUser('PM');
    $task = editableTask(['due_date' => now()->subDays(2)->toDateString(), 'status' => TaskStatus::OnProgress->value]);
    app(TaskService::class)->markOverdueTasks();

    // Still in the past — stays OVER.
    $this->actingAs($pm)->put(route('tasks.update', $task), taskUpdatePayload($task->fresh(), ['due_date' => now()->subDay()->toDateString()]))
        ->assertSessionHasNoErrors();
    expect($task->fresh()->status)->toBe(TaskStatus::Over);

    $this->actingAs($pm)->put(route('tasks.update', $task), taskUpdatePayload($task->fresh(), ['due_date' => now('Asia/Jakarta')->toDateString()]))
        ->assertSessionHasNoErrors();

    expect($task->fresh()->status)->toBe(TaskStatus::OnProgress)
        ->and($task->fresh()->pre_overdue_status)->toBeNull();
});

test('an OVER task without a remembered status falls back to PENDING', function () {
    $task = editableTask(['due_date' => now()->subDays(2)->toDateString(), 'status' => TaskStatus::Over->value, 'pre_overdue_status' => null]);

    $this->actingAs(taskUpdateUser('PM'))
        ->put(route('tasks.update', $task), taskUpdatePayload($task, ['due_date' => now()->addDays(3)->toDateString()]))
        ->assertSessionHasNoErrors();

    expect($task->fresh()->status)->toBe(TaskStatus::Pending);
});

test('moving an OVER task on through a status update drops the remembered status', function () {
    $staff = taskUpdateUser('FIELD_STAFF');
    $task = editableTask(['assignee_id' => $staff->id, 'status' => TaskStatus::Over->value, 'pre_overdue_status' => TaskStatus::OnProgress->value]);

    $this->actingAs($staff)->patch(route('tasks.updateStatus', $task), ['status' => 'DONE'])->assertSessionHasNoErrors();

    expect($task->fresh()->status)->toBe(TaskStatus::Done)
        ->and($task->fresh()->pre_overdue_status)->toBeNull();
});

// ── Delete guard ─────────────────────────────────────────────────────────

test('a PM deletes a task without history, leaving an audit snapshot', function () {
    $pm = taskUpdateUser('PM');
    $task = editableTask(['title' => 'Task salah input']);

    $this->actingAs($pm)->delete(route('tasks.destroy', $task))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $log = AuditLog::where('action', 'task.deleted')->sole();
    expect(Task::find($task->id))->toBeNull()
        ->and($log->model_id)->toBe($task->id)
        ->and($log->user_id)->toBe($pm->id)
        ->and($log->old_values)->toMatchArray(['title' => 'Task salah input', 'status' => 'PENDING', 'rate_per_task' => '150000.00'])
        ->and($log->new_values)->toBeNull();
});

test('a task with history cannot be deleted', function (Closure $addHistory) {
    $task = editableTask(['status' => TaskStatus::OnProgress->value]);
    $addHistory($task);

    $this->actingAs(taskUpdateUser('PM'))->delete(route('tasks.destroy', $task))
        ->assertSessionHasErrors(['task' => TaskService::HISTORY_MESSAGE]);

    expect(Task::find($task->id))->not->toBeNull()
        ->and(AuditLog::where('action', 'task.deleted')->exists())->toBeFalse();
})->with([
    'daily form' => fn (Task $task) => DailyTaskForm::factory()->create(['task_id' => $task->id, 'staff_id' => $task->assignee_id]),
    'overtime request' => fn (Task $task) => OvertimeRequest::factory()->create(['task_id' => $task->id, 'project_id' => $task->project_id, 'staff_id' => $task->assignee_id]),
    'penalty' => fn (Task $task) => Penalty::factory()->create(['staff_id' => $task->assignee_id, 'type' => 'TASK_OVERDUE', 'reference_id' => $task->id]),
    'wage payment' => fn (Task $task) => markWagePaid($task),
]);

test('a DONE task cannot be deleted', function () {
    $task = editableTask(['status' => TaskStatus::Done->value]);

    $this->actingAs(taskUpdateUser('PM'))->delete(route('tasks.destroy', $task))
        ->assertSessionHasErrors('task');

    expect(Task::find($task->id))->not->toBeNull();
});

// ── "Hari ini / Minggu ini / Terlambat" filter (PRD §4.5) ────────────────

test('the task list filters by due today, this week (Mon–Sun) and overdue', function () {
    // Wednesday — the week runs Mon 28 Sep to Sun 4 Oct 2026.
    Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', 'Asia/Jakarta'));

    $today = editableTask(['due_date' => '2026-09-30']);
    $sunday = editableTask(['due_date' => '2026-10-04']);
    $lateOpen = editableTask(['due_date' => '2026-09-29', 'status' => TaskStatus::OnProgress->value]);
    $lateDone = editableTask(['due_date' => '2026-09-29', 'status' => TaskStatus::Done->value]);
    $lastMonth = editableTask(['due_date' => '2026-08-20', 'status' => TaskStatus::Over->value]);
    $nextWeek = editableTask(['due_date' => '2026-10-05']);

    $pm = taskUpdateUser('PM');
    $ids = fn (string $due) => collect($this->actingAs($pm)->get(route('tasks.index', ['due' => $due]))
        ->assertOk()
        ->viewData('page')['props']['tasks']['data'])->pluck('id')->sort()->values()->all();

    expect($ids('today'))->toBe([$today->id])
        ->and($ids('week'))->toBe(collect([$today->id, $sunday->id, $lateOpen->id, $lateDone->id])->sort()->values()->all())
        ->and($ids('overdue'))->toBe(collect([$lateOpen->id, $lastMonth->id])->sort()->values()->all())
        ->and($ids('bogus'))->toHaveCount(6);

    expect($nextWeek)->not->toBeNull();
});

test('the due filter keeps field staff scoped to their own tasks', function () {
    $staff = taskUpdateUser('FIELD_STAFF');
    $own = editableTask(['assignee_id' => $staff->id, 'due_date' => now('Asia/Jakarta')->toDateString()]);
    editableTask(['due_date' => now('Asia/Jakarta')->toDateString()]);

    $this->actingAs($staff)->get(route('tasks.index', ['due' => 'today']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1)
            ->where('tasks.data.0.id', $own->id)
            ->where('filters.due', 'today'));
});
