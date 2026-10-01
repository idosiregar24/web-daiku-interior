<?php

use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\Milestone;
use App\Models\Notification;
use App\Models\Penalty;
use App\Models\Project;
use App\Models\Task;
use App\Models\Termin;
use App\Models\User;
use App\Services\MilestoneService;
use App\Services\PenaltyService;
use App\Services\TaskService;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function projectUpdateUser(string $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole($role);

    return $user;
}

/** A full, valid "Edit Proyek" payload for `$project`, with `$overrides`. */
function projectUpdatePayload(Project $project, array $overrides = []): array
{
    return [
        'name' => $project->name,
        'start_date' => $project->start_date->toDateString(),
        'end_date' => $project->end_date?->toDateString(),
        'contract_value' => (float) $project->contract_value,
        'status' => $project->status->value,
        ...$overrides,
    ];
}

function projectOwnedBy(User $pm, array $attributes = []): Project
{
    return Project::factory()->create([
        'pm_id' => $pm->id,
        'start_date' => '2026-09-01',
        'contract_value' => 100_000_000,
        ...$attributes,
    ]);
}

// ── RBAC (PRD §7.1 "Project (overview)" + ProjectPolicy::update) ─────────

test('the CEO can edit any project', function () {
    $project = projectOwnedBy(projectUpdateUser('PM'));

    $this->actingAs(projectUpdateUser('CEO'))
        ->put(route('projects.update', $project), projectUpdatePayload($project, ['name' => 'Proyek Rumah Baru']))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($project->fresh()->name)->toBe('Proyek Rumah Baru');
});

test('a PM can edit their own project', function () {
    $pm = projectUpdateUser('PM');
    $project = projectOwnedBy($pm);

    $this->actingAs($pm)
        ->put(route('projects.update', $project), projectUpdatePayload($project, ['end_date' => '2026-12-31']))
        ->assertSessionHasNoErrors();

    expect($project->fresh()->end_date->toDateString())->toBe('2026-12-31');
});

test('a PM cannot edit another PM\'s project', function () {
    $project = projectOwnedBy(projectUpdateUser('PM'));

    $this->actingAs(projectUpdateUser('PM'))
        ->put(route('projects.update', $project), projectUpdatePayload($project, ['name' => 'Diambil alih']))
        ->assertForbidden();

    expect($project->fresh()->name)->not->toBe('Diambil alih');
});

test('roles without project write access get 403', function (string $role) {
    $project = projectOwnedBy(projectUpdateUser('PM'));

    $this->actingAs(projectUpdateUser($role))
        ->put(route('projects.update', $project), projectUpdatePayload($project, ['name' => 'X']))
        ->assertForbidden();
})->with(['MARKETING', 'DESIGNER', 'ESTIMATOR', 'QA', 'FINANCE', 'LOGISTICS', 'FIELD_STAFF']);

test('the detail page offers "Edit Proyek" only to the CEO and the project\'s own PM', function () {
    $pm = projectUpdateUser('PM');
    $project = projectOwnedBy($pm);

    $this->actingAs($pm)->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canEditProject', true)
            ->where('canChangePm', false)
            ->has('projectManagers', 0));

    $this->actingAs(projectUpdateUser('CEO'))->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canEditProject', true)
            ->where('canChangePm', true)
            ->has('projectManagers', 1));

    $this->actingAs(projectUpdateUser('PM'))->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page->where('canEditProject', false));

    $this->actingAs(projectUpdateUser('FINANCE'))->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page->where('canEditProject', false));
});

// ── PM re-assignment (PRD §4.4 "PM di-assign oleh CEO") ──────────────────

test('the CEO re-assigns the PM, notifying both and auditing the change', function () {
    $ceo = projectUpdateUser('CEO');
    $oldPm = projectUpdateUser('PM', ['name' => 'Budi PM']);
    $newPm = projectUpdateUser('PM', ['name' => 'Sari PM']);
    $project = projectOwnedBy($oldPm);

    $this->actingAs($ceo)
        ->put(route('projects.update', $project), projectUpdatePayload($project, ['pm_id' => $newPm->id]))
        ->assertSessionHasNoErrors();

    expect($project->fresh()->pm_id)->toBe($newPm->id)
        ->and(Notification::where('user_id', $newPm->id)->where('type', 'project_pm_assigned')->where('metadata->project_id', $project->id)->exists())->toBeTrue()
        ->and(Notification::where('user_id', $oldPm->id)->where('type', 'project_pm_unassigned')->exists())->toBeTrue();

    $log = AuditLog::where('action', 'project.updated')->sole();
    expect($log->user_id)->toBe($ceo->id)
        ->and($log->old_values)->toMatchArray(['pm_id' => $oldPm->id, 'pm' => 'Budi PM'])
        ->and($log->new_values)->toMatchArray(['pm_id' => $newPm->id, 'pm' => 'Sari PM'])
        ->and($log->new_values)->not->toHaveKey('name');
});

test('a PM cannot hand their project to another PM', function () {
    $pm = projectUpdateUser('PM');
    $other = projectUpdateUser('PM');
    $project = projectOwnedBy($pm);

    $this->actingAs($pm)
        ->put(route('projects.update', $project), projectUpdatePayload($project, ['pm_id' => $other->id]))
        ->assertSessionHasErrors('pm_id');

    expect($project->fresh()->pm_id)->toBe($pm->id);
});

test('a PM may send their own pm_id back unchanged', function () {
    $pm = projectUpdateUser('PM');
    $project = projectOwnedBy($pm);

    $this->actingAs($pm)
        ->put(route('projects.update', $project), projectUpdatePayload($project, ['pm_id' => $pm->id, 'name' => 'Nama Baru']))
        ->assertSessionHasNoErrors();

    expect($project->fresh()->name)->toBe('Nama Baru');
});

test('the new PM must be an active user with the PM role', function (Closure $makeUser) {
    $project = projectOwnedBy(projectUpdateUser('PM'));

    $this->actingAs(projectUpdateUser('CEO'))
        ->put(route('projects.update', $project), projectUpdatePayload($project, ['pm_id' => $makeUser()->id]))
        ->assertSessionHasErrors('pm_id');
})->with([
    'inactive PM' => fn () => projectUpdateUser('PM', ['is_active' => false]),
    'not a PM' => fn () => projectUpdateUser('FINANCE'),
]);

// ── Status (ACTIVE ↔ ON_HOLD, → CANCELLED with reason; COMPLETED only via QA) ──

test('a project can be put ON_HOLD and back to ACTIVE', function () {
    $pm = projectUpdateUser('PM');
    $project = projectOwnedBy($pm);

    $this->actingAs($pm)->put(route('projects.update', $project), projectUpdatePayload($project, ['status' => 'ON_HOLD', 'note' => 'Klien renovasi ditunda']))
        ->assertSessionHasNoErrors();
    expect($project->fresh()->status)->toBe(ProjectStatus::OnHold);

    $this->actingAs($pm)->put(route('projects.update', $project), projectUpdatePayload($project->fresh(), ['status' => 'ACTIVE']))
        ->assertSessionHasNoErrors();
    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('cancelling requires a reason, which is audited and shown on the detail page', function () {
    $pm = projectUpdateUser('PM');
    $project = projectOwnedBy($pm, ['status' => ProjectStatus::OnHold->value]);

    $this->actingAs($pm)->put(route('projects.update', $project), projectUpdatePayload($project, ['status' => 'CANCELLED']))
        ->assertSessionHasErrors('note');
    expect($project->fresh()->status)->toBe(ProjectStatus::OnHold);

    $this->actingAs($pm)->put(route('projects.update', $project), projectUpdatePayload($project, ['status' => 'CANCELLED', 'note' => 'Klien membatalkan kontrak']))
        ->assertSessionHasNoErrors();

    expect($project->fresh()->status)->toBe(ProjectStatus::Cancelled)
        ->and(AuditLog::where('action', 'project.updated')->sole()->new_values)
        ->toMatchArray(['status' => 'CANCELLED', 'note' => 'Klien membatalkan kontrak']);

    $this->actingAs($pm)->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canEditProject', false)
            ->where('statusNote.note', 'Klien membatalkan kontrak')
            ->where('statusNote.by', $pm->name));
});

test('COMPLETED can never be set manually', function () {
    $pm = projectUpdateUser('PM');
    $project = projectOwnedBy($pm);

    $this->actingAs($pm)->put(route('projects.update', $project), projectUpdatePayload($project, ['status' => 'COMPLETED']))
        ->assertSessionHasErrors('status');

    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('COMPLETED and CANCELLED projects are read-only, even for the CEO', function (ProjectStatus $status) {
    $project = projectOwnedBy(projectUpdateUser('PM'), ['status' => $status->value]);

    $this->actingAs(projectUpdateUser('CEO'))
        ->put(route('projects.update', $project), projectUpdatePayload($project, ['status' => 'ACTIVE', 'name' => 'Dihidupkan lagi']))
        ->assertSessionHasErrors('status');

    expect($project->fresh()->status)->toBe($status)
        ->and($project->fresh()->name)->not->toBe('Dihidupkan lagi');
})->with([ProjectStatus::Completed, ProjectStatus::Cancelled]);

test('the end date cannot be before the start date', function () {
    $pm = projectUpdateUser('PM');
    $project = projectOwnedBy($pm);

    $this->actingAs($pm)->put(route('projects.update', $project), projectUpdatePayload($project, ['end_date' => '2026-08-01']))
        ->assertSessionHasErrors('end_date');
});

test('saving without any change writes no audit row', function () {
    $pm = projectUpdateUser('PM');
    $project = projectOwnedBy($pm);

    $this->actingAs($pm)->put(route('projects.update', $project), projectUpdatePayload($project))
        ->assertSessionHasNoErrors();

    expect(AuditLog::where('action', 'project.updated')->exists())->toBeFalse();
});

// ── Contract value ↔ termins ─────────────────────────────────────────────

test('changing the contract value re-derives every unpaid termin amount', function () {
    $pm = projectUpdateUser('PM');
    $project = projectOwnedBy($pm);
    $first = Termin::factory()->create(['project_id' => $project->id, 'termin_number' => 1, 'percentage' => 30, 'amount' => 30_000_000, 'bank_account_id' => null]);
    $second = Termin::factory()->create(['project_id' => $project->id, 'termin_number' => 2, 'percentage' => 70, 'amount' => 70_000_000, 'bank_account_id' => null]);

    $this->actingAs($pm)->put(route('projects.update', $project), projectUpdatePayload($project, ['contract_value' => 150_000_000]))
        ->assertSessionHasNoErrors();

    expect((float) $project->fresh()->contract_value)->toBe(150_000_000.0)
        ->and((float) $first->fresh()->amount)->toBe(45_000_000.0)
        ->and((float) $first->fresh()->sisa_piutang)->toBe(45_000_000.0)
        ->and((float) $second->fresh()->amount)->toBe(105_000_000.0)
        ->and(AuditLog::where('action', 'project.updated')->sole()->new_values)
        ->toMatchArray(['contract_value' => '150000000.00', 'termins_recalculated' => 2]);
});

test('the contract value is locked once a termin received any payment', function (array $paidState) {
    $pm = projectUpdateUser('PM');
    $project = projectOwnedBy($pm);
    $termin = Termin::factory()->create(['project_id' => $project->id, 'percentage' => 30, 'amount' => 30_000_000, 'bank_account_id' => null, ...$paidState]);

    $this->actingAs($pm)->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page->where('hasTerminPayments', true));

    $this->actingAs($pm)->put(route('projects.update', $project), projectUpdatePayload($project, ['contract_value' => 200_000_000]))
        ->assertSessionHasErrors('contract_value');

    expect((float) $project->fresh()->contract_value)->toBe(100_000_000.0)
        ->and((float) $termin->fresh()->amount)->toBe(30_000_000.0);

    // Everything else stays editable.
    $this->actingAs($pm)->put(route('projects.update', $project), projectUpdatePayload($project, ['name' => 'Tetap Bisa Diubah']))
        ->assertSessionHasNoErrors();
    expect($project->fresh()->name)->toBe('Tetap Bisa Diubah');
})->with([
    'DP received' => [['dp_amount' => 5_000_000]],
    'pelunasan received' => [['pelunasan' => 10_000_000]],
    'paid in full' => [['pelunasan' => 30_000_000, 'status' => 'PAID']],
]);

// ── Non-ACTIVE projects: automation paused, plan frozen when closed ──────

test('the overdue jobs skip tasks and milestones of non-ACTIVE projects', function (ProjectStatus $status) {
    $paused = Project::factory()->create(['status' => $status->value]);
    $active = Project::factory()->create();
    $pausedTask = Task::factory()->create(['project_id' => $paused->id, 'due_date' => now()->subDays(2)->toDateString(), 'status' => TaskStatus::OnProgress->value]);
    $activeTask = Task::factory()->create(['project_id' => $active->id, 'due_date' => now()->subDays(2)->toDateString(), 'status' => TaskStatus::OnProgress->value]);
    $pausedMilestone = Milestone::factory()->create(['project_id' => $paused->id, 'status' => MilestoneStatus::InProgress->value, 'target_date' => now()->subDay()]);
    $activeMilestone = Milestone::factory()->create(['project_id' => $active->id, 'status' => MilestoneStatus::InProgress->value, 'target_date' => now()->subDay()]);

    expect(app(TaskService::class)->markOverdueTasks())->toBe(1)
        ->and(app(MilestoneService::class)->markOverdueMilestones())->toBe(1)
        ->and($pausedTask->fresh()->status)->toBe(TaskStatus::OnProgress)
        ->and($activeTask->fresh()->status)->toBe(TaskStatus::Over)
        ->and($pausedMilestone->fresh()->status)->toBe(MilestoneStatus::InProgress)
        ->and($activeMilestone->fresh()->status)->toBe(MilestoneStatus::Overdue);
})->with([ProjectStatus::OnHold, ProjectStatus::Cancelled, ProjectStatus::Completed]);

test('daily form reminders and penalties skip staff whose open tasks are all on non-ACTIVE projects', function () {
    $onHoldOnly = projectUpdateUser('FIELD_STAFF');
    $mixed = projectUpdateUser('FIELD_STAFF');
    $onHold = Project::factory()->create(['status' => ProjectStatus::OnHold->value]);
    $active = Project::factory()->create();
    Task::factory()->create(['project_id' => $onHold->id, 'assignee_id' => $onHoldOnly->id, 'status' => TaskStatus::OnProgress->value]);
    Task::factory()->create(['project_id' => $onHold->id, 'assignee_id' => $mixed->id, 'status' => TaskStatus::OnProgress->value]);
    Task::factory()->create(['project_id' => $active->id, 'assignee_id' => $mixed->id, 'status' => TaskStatus::OnProgress->value]);

    $service = app(PenaltyService::class);

    expect($service->staffMissingDailyForm(now('Asia/Jakarta'))->pluck('id')->all())->toBe([$mixed->id])
        ->and($service->sendDailyFormReminders())->toBe(1)
        ->and($service->runDailyCheck())->toBe(1)
        ->and(Penalty::where('staff_id', $onHoldOnly->id)->exists())->toBeFalse()
        ->and(Penalty::where('staff_id', $mixed->id)->exists())->toBeTrue();
});

test('tasks and milestones cannot be added to a closed project', function (ProjectStatus $status) {
    $pm = projectUpdateUser('PM');
    $staff = projectUpdateUser('FIELD_STAFF');
    $project = projectOwnedBy($pm, ['status' => $status->value]);

    $this->actingAs($pm)->post(route('tasks.store', $project), [
        'title' => 'Pasang kusen',
        'assignee_id' => $staff->id,
        'due_date' => now()->addWeek()->toDateString(),
    ])->assertSessionHasErrors('project');

    $this->actingAs($pm)->post(route('milestones.store', $project), [
        'name' => 'Finishing',
        'target_date' => now()->addWeek()->toDateString(),
    ])->assertSessionHasErrors('project');

    expect($project->tasks()->count())->toBe(0)
        ->and($project->milestones()->count())->toBe(0);
})->with([ProjectStatus::Cancelled, ProjectStatus::Completed]);

test('an ON_HOLD project still accepts new tasks and milestones', function () {
    $pm = projectUpdateUser('PM');
    $staff = projectUpdateUser('FIELD_STAFF');
    $project = projectOwnedBy($pm, ['status' => ProjectStatus::OnHold->value]);

    $this->actingAs($pm)->post(route('tasks.store', $project), [
        'title' => 'Pasang kusen',
        'assignee_id' => $staff->id,
        'due_date' => now()->addWeek()->toDateString(),
    ])->assertSessionHasNoErrors();

    $this->actingAs($pm)->post(route('milestones.store', $project), [
        'name' => 'Finishing',
        'target_date' => now()->addWeek()->toDateString(),
    ])->assertSessionHasNoErrors();

    expect($project->tasks()->count())->toBe(1)
        ->and($project->milestones()->count())->toBe(1);
});

test('a milestone of a cancelled project cannot be handed to QA', function () {
    $pm = projectUpdateUser('PM');
    $project = projectOwnedBy($pm, ['status' => ProjectStatus::Cancelled->value]);
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'status' => MilestoneStatus::InProgress->value]);

    $this->actingAs($pm)->post(route('milestones.markDone', $milestone))
        ->assertSessionHasErrors('project');

    expect($milestone->fresh()->status)->toBe(MilestoneStatus::InProgress)
        ->and($milestone->qaForm()->exists())->toBeFalse();
});
