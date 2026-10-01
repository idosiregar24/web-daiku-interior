<?php

use App\Enums\MilestoneStatus;
use App\Enums\OvertimeStatus;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Milestone;
use App\Models\OvertimeRequest;
use App\Models\ProgressLog;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    // A Wednesday — "this week" runs to Sunday 4 Oct.
    $this->travelTo(Carbon::parse('2026-09-30 10:00:00'));
});

function projectDashboardUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * Two PMs. PM A: an ACTIVE project full of signals, an ON_HOLD one (paused —
 * nothing flagged late) and a COMPLETED one (out of scope). PM B: one
 * ACTIVE project with its own overdue task/QA/overtime.
 *
 * @return array{pmA: User, pmB: User, active: Project, onHold: Project, other: Project}
 */
function monitorFixture(): array
{
    $pmA = projectDashboardUser('PM');
    $pmB = projectDashboardUser('PM');
    $active = Project::factory()->create(['pm_id' => $pmA->id, 'name' => 'Alpha Cafe']);
    $onHold = Project::factory()->create(['pm_id' => $pmA->id, 'name' => 'Beta Kantor', 'status' => ProjectStatus::OnHold->value]);
    $completed = Project::factory()->create(['pm_id' => $pmA->id, 'status' => ProjectStatus::Completed->value]);
    $other = Project::factory()->create(['pm_id' => $pmB->id, 'name' => 'Gamma Toko']);

    $task = fn (Project $project, string $due, TaskStatus $status = TaskStatus::OnProgress, array $extra = []) => Task::factory()->create([
        'project_id' => $project->id, 'due_date' => $due, 'status' => $status->value, ...$extra,
    ]);

    $task($active, '2026-09-27');                                  // 3 days late
    $task($active, '2026-09-29', TaskStatus::Over);                // 1 day late
    $task($active, '2026-09-25', TaskStatus::Done);                // done — not late
    $task($active, '2026-09-30', TaskStatus::Pending);             // due today
    $task($active, '2026-10-04', TaskStatus::Pending);             // due Sunday
    $task($active, '2026-10-10', TaskStatus::Pending);             // next week
    $task($onHold, '2026-09-26');                                  // paused project
    $task($completed, '2026-09-29');                               // closed project
    $task($other, '2026-09-28', TaskStatus::OnProgress, ['title' => 'Task PM lain']);

    $milestone = fn (Project $project, MilestoneStatus $status, string $target) => Milestone::factory()->create([
        'project_id' => $project->id, 'status' => $status->value, 'target_date' => $target,
    ]);
    $milestone($active, MilestoneStatus::QaWaiting, '2026-09-28');
    $milestone($active, MilestoneStatus::Pending, '2026-10-03');   // due within 7 days
    $milestone($active, MilestoneStatus::Overdue, '2026-09-20');
    $milestone($active, MilestoneStatus::InProgress, '2026-10-20'); // not soon
    $milestone($active, MilestoneStatus::Completed, '2026-09-10');
    $milestone($onHold, MilestoneStatus::QaWaiting, '2026-09-29');  // still waiting on QA
    $milestone($onHold, MilestoneStatus::Pending, '2026-09-01');    // paused — not flagged
    $milestone($other, MilestoneStatus::QaWaiting, '2026-09-29');

    OvertimeRequest::factory()->create(['project_id' => $active->id]);
    OvertimeRequest::factory()->create(['project_id' => $active->id, 'status' => OvertimeStatus::PendingFinance->value]);
    OvertimeRequest::factory()->create(['project_id' => $other->id]);

    ProgressLog::factory()->create(['project_id' => $active->id, 'percentage' => 40, 'log_date' => '2026-09-25']);
    ProgressLog::factory()->create(['project_id' => $active->id, 'percentage' => 60, 'log_date' => '2026-09-29']);

    return compact('pmA', 'pmB', 'active', 'onHold', 'other');
}

// ── RBAC: CEO + PM only ──────────────────────────────────────────────────

test('CEO and PM open Monitor Proyek', function (string $role) {
    $this->actingAs(projectDashboardUser($role))->get(route('projects.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Dashboard')
            ->has('stats')
            ->has('projects')
            ->has('overdue')
            ->has('dueSoon')
            ->has('milestones')
            ->has('pendingOvertime')
            ->has('filters')
            ->has('pmOptions'));
})->with(['CEO', 'PM']);

test('every other role is refused Monitor Proyek', function (string $role) {
    $this->actingAs(projectDashboardUser($role))->get(route('projects.dashboard'))->assertForbidden();
})->with(['MARKETING', 'DESIGNER', 'ESTIMATOR', 'QA', 'FINANCE', 'LOGISTICS', 'FIELD_STAFF']);

// ── PM scoping & content ─────────────────────────────────────────────────

test('a PM monitors only their own active/on-hold projects, lateness on active ones only', function () {
    ['pmA' => $pmA, 'active' => $active, 'onHold' => $onHold] = monitorFixture();

    $this->actingAs($pmA)->get(route('projects.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats', [
                'projects' => 2,
                'onHold' => 1,
                'overdueTasks' => 2,
                'dueToday' => 1,
                'dueThisWeek' => 2,
                'milestonesOverdue' => 1,
                'qaWaiting' => 2,
                'pendingOvertime' => 1,
            ])
            ->where('projects.0.id', $active->id)
            ->where('projects.0.progress', 60)
            ->where('projects.0.progressDate', '2026-09-29')
            ->where('projects.0.overdueTasks', 2)
            ->where('projects.0.milestonesTotal', 5)
            ->where('projects.0.milestonesCompleted', 1)
            ->where('projects.1.id', $onHold->id)
            ->where('projects.1.overdueTasks', 0)
            ->has('overdue', 1)
            ->where('overdue.0.projectId', $active->id)
            ->where('overdue.0.total', 2)
            ->where('overdue.0.maxDaysLate', 3)
            ->where('dueSoon.0.isToday', true)
            ->where('dueSoon.1.dueDate', '2026-10-04')
            ->where('milestones.0.reason', 'OVERDUE')
            ->where('milestones.1.reason', 'QA_WAITING')
            ->where('milestones.2.reason', 'QA_WAITING')
            ->where('milestones.3.reason', 'DUE_SOON')
            ->where('milestones.3.daysLeft', 3)
            ->where('pmOptions', [])
            ->where('filters.pm_id', null));
});

test("another PM's overdue tasks, milestones and overtime never reach a PM — not even via ?pm_id", function () {
    ['pmA' => $pmA, 'pmB' => $pmB, 'other' => $other] = monitorFixture();

    $response = $this->actingAs($pmA)->get(route('projects.dashboard', ['pm_id' => $pmB->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.pm_id', null)
            ->where('stats.projects', 2));

    $props = $response->viewData('page')['props'];
    expect(collect($props['projects'])->pluck('id'))->not->toContain($other->id)
        ->and(collect($props['overdue'])->pluck('projectId'))->not->toContain($other->id)
        ->and(collect($props['milestones'])->pluck('projectId'))->not->toContain($other->id)
        ->and(collect($props['pendingOvertime'])->pluck('projectId'))->not->toContain($other->id);
    $response->assertDontSee('Task PM lain');
});

test('the CEO monitors every PM and can narrow to one', function () {
    ['pmB' => $pmB, 'other' => $other] = monitorFixture();
    $ceo = projectDashboardUser('CEO');

    $this->actingAs($ceo)->get(route('projects.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.projects', 3)
            ->where('stats.overdueTasks', 3)
            ->has('overdue', 2)
            ->has('pmOptions', 2));

    $this->actingAs($ceo)->get(route('projects.dashboard', ['pm_id' => $pmB->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.pm_id', $pmB->id)
            ->where('stats.projects', 1)
            ->where('projects.0.id', $other->id)
            ->where('overdue.0.assignees.0.tasks.0.title', 'Task PM lain')
            ->where('overdue.0.assignees.0.tasks.0.daysLate', 2));
});
