<?php

use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\QaStatus;
use App\Models\Milestone;
use App\Models\Notification;
use App\Models\Penalty;
use App\Models\Project;
use App\Models\User;
use App\Services\MilestoneService;
use App\Services\QaFormService;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function lifecycleUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function approveMilestone(Milestone $milestone): void
{
    app(MilestoneService::class)->markDone($milestone->fresh());
    $qaForm = $milestone->qaForm()->first();
    app(QaFormService::class)->review($qaForm, 'approve', $qaForm->checklist_data, null, lifecycleUser('QA'));
}

// ── Milestone OVERDUE scheduler (CSV Sprint 6) ───────────────────────────

test('milestones still being worked on go OVERDUE past their target date, once', function () {
    $pm = lifecycleUser('PM');
    $project = Project::factory()->create(['pm_id' => $pm->id]);
    $late = Milestone::factory()->create(['project_id' => $project->id, 'status' => MilestoneStatus::InProgress->value, 'target_date' => now()->subDay()]);
    $waitingQa = Milestone::factory()->create(['project_id' => $project->id, 'status' => MilestoneStatus::QaWaiting->value, 'target_date' => now()->subDay()]);
    $onTrack = Milestone::factory()->create(['project_id' => $project->id, 'status' => MilestoneStatus::Pending->value, 'target_date' => now()->addDay()]);

    $service = app(MilestoneService::class);

    expect($service->markOverdueMilestones())->toBe(1)
        ->and($service->markOverdueMilestones())->toBe(0)
        ->and($late->fresh()->status)->toBe(MilestoneStatus::Overdue)
        // Handed to QA — the delay is QA's, not the team's.
        ->and($waitingQa->fresh()->status)->toBe(MilestoneStatus::QaWaiting)
        ->and($onTrack->fresh()->status)->toBe(MilestoneStatus::Pending)
        ->and(Notification::where('user_id', $pm->id)->where('type', 'milestone_overdue')->count())->toBe(1);
});

test('an OVERDUE milestone can still be handed to QA', function () {
    $milestone = Milestone::factory()->create(['status' => MilestoneStatus::Overdue->value]);

    app(MilestoneService::class)->markDone($milestone);

    expect($milestone->fresh()->status)->toBe(MilestoneStatus::QaWaiting)
        ->and($milestone->qaForm()->first()->status)->toBe(QaStatus::Pending);
});

// ── Project completion flow (CSV Sprint 6) ───────────────────────────────

test('a project completes when QA approves its last milestone', function () {
    $pm = lifecycleUser('PM');
    $ceo = lifecycleUser('CEO');
    $project = Project::factory()->create(['pm_id' => $pm->id]);
    $first = Milestone::factory()->create(['project_id' => $project->id, 'order' => 0, 'status' => MilestoneStatus::InProgress->value]);
    $second = Milestone::factory()->create(['project_id' => $project->id, 'order' => 1]);

    approveMilestone($first);
    expect($project->fresh()->status)->toBe(ProjectStatus::Active)
        ->and($second->fresh()->status)->toBe(MilestoneStatus::InProgress);

    approveMilestone($second);
    $project->refresh();

    expect($project->status)->toBe(ProjectStatus::Completed)
        ->and($project->end_date->toDateString())->toBe(now('Asia/Jakarta')->toDateString())
        ->and(Notification::where('type', 'project_completed')->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$pm->id, $ceo->id])->sort()->values()->all());
});

test('a rejected last milestone leaves the project active', function () {
    $project = Project::factory()->create();
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'status' => MilestoneStatus::InProgress->value]);

    app(MilestoneService::class)->markDone($milestone);
    $qaForm = $milestone->qaForm()->first();
    app(QaFormService::class)->review($qaForm, 'reject', $qaForm->checklist_data, 'Belum rapi', lifecycleUser('QA'));

    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

// ── Penalty list summary (CSV Sprint 6) ──────────────────────────────────

test('the penalty page summarizes per tukang and totals', function () {
    $a = lifecycleUser('FIELD_STAFF');
    $b = lifecycleUser('FIELD_STAFF');
    Penalty::factory()->count(2)->create(['staff_id' => $a->id, 'amount' => 50000]);
    Penalty::factory()->create(['staff_id' => $b->id, 'amount' => 50000]);

    $this->actingAs(lifecycleUser('PM'))->get(route('penalties.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('grandTotal', 150000)
            ->has('perStaff', 2)
            ->where('perStaff.0.staffId', $a->id)
            ->where('perStaff.0.count', 2)
            // PM has no Family Fund access (PRD §7.1) — no link for them.
            ->where('canViewFamilyFund', false));
});

test('a tukang summary only ever covers their own penalties', function () {
    $self = lifecycleUser('FIELD_STAFF');
    Penalty::factory()->create(['staff_id' => $self->id, 'amount' => 50000]);
    Penalty::factory()->count(3)->create(['amount' => 50000]);

    $this->actingAs($self)->get(route('penalties.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('grandTotal', 50000)
            ->has('perStaff', 1)
            ->has('penalties.data', 1));
});
