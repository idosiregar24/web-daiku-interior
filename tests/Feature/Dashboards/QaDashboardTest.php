<?php

use App\Enums\MilestoneStatus;
use App\Enums\QaStatus;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\QaForm;
use App\Models\Task;
use App\Models\User;
use App\Services\DivisionDashboardService;
use App\Services\QaFormService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo(Carbon::parse('2026-09-30 10:00:00'));
});

function qaDashboardUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** A QA form on its own milestone of `$project` (the factory would otherwise mix two projects). */
function qaDashboardForm(Project $project, array $form = [], array $milestone = []): QaForm
{
    $milestone = Milestone::factory()->create([
        'project_id' => $project->id,
        'status' => MilestoneStatus::QaWaiting->value,
        ...$milestone,
    ]);

    return QaForm::factory()->create(['project_id' => $project->id, 'milestone_id' => $milestone->id, ...$form]);
}

// ── RBAC: CEO + QA only ──────────────────────────────────────────────────

test('CEO and QA open the QA dashboard', function (string $role) {
    $this->actingAs(qaDashboardUser($role))->get(route('qa-forms.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('QA/Dashboard')
            ->has('stats')
            ->has('pending')
            ->has('repeatRejections.rows'));
})->with(['CEO', 'QA']);

test('every other role is refused the QA dashboard', function (string $role) {
    $this->actingAs(qaDashboardUser($role))->get(route('qa-forms.dashboard'))->assertForbidden();
})->with(['MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'FINANCE', 'LOGISTICS', 'FIELD_STAFF']);

// ── Queue ────────────────────────────────────────────────────────────────

test('the review queue is oldest hand-over first, a re-submitted form waiting since its re-submission', function () {
    $project = Project::factory()->create();
    Milestone::factory()->create(['project_id' => $project->id]); // keeps the project from auto-completing

    $firstRound = qaDashboardForm($project, ['created_at' => '2026-09-25 09:00:00'], ['updated_at' => '2026-09-25 09:00:00']);
    // Created 20 Sep, rejected 22 Sep, PM re-submitted (milestone → QA_WAITING) 28 Sep.
    $secondRound = qaDashboardForm(
        $project,
        ['created_at' => '2026-09-20 09:00:00', 'rejection_count' => 1, 'reviewed_at' => '2026-09-22 09:00:00'],
        ['updated_at' => '2026-09-28 09:00:00'],
    );
    qaDashboardForm($project, ['status' => QaStatus::Approved->value, 'reviewed_at' => now()], ['status' => MilestoneStatus::Completed->value]);

    $this->actingAs(qaDashboardUser('QA'))->get(route('qa-forms.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('pending', 2)
            ->where('pending.0.id', $firstRound->id)
            ->where('pending.0.daysWaiting', 5)
            ->where('pending.0.round', 1)
            ->where('pending.1.id', $secondRound->id)
            ->where('pending.1.daysWaiting', 2)
            ->where('pending.1.round', 2));
});

// ── Decisions & review time ──────────────────────────────────────────────

test('decisions this month, rejection rate and first-round review time come from the audit trail', function () {
    $qa = qaDashboardUser('QA');
    $project = Project::factory()->create();
    Milestone::factory()->create(['project_id' => $project->id]);
    $service = app(QaFormService::class);
    $checklist = [['label' => 'Rapi', 'passed' => true, 'note' => null]];

    // Rejected on the 27th, fixed and approved today: one of each this month.
    $fixed = qaDashboardForm($project, ['created_at' => '2026-09-26 10:00:00']);
    $this->travelTo(Carbon::parse('2026-09-27 10:00:00'));
    $service->review($fixed, 'reject', $checklist, 'Cat belum rata', $qa);          // first decision after 24h
    $this->travelTo(Carbon::parse('2026-09-30 10:00:00'));
    $service->review($fixed->fresh(), 'approve', $checklist, null, $qa);

    $quick = qaDashboardForm($project, ['created_at' => '2026-09-29 22:00:00']);
    $service->review($quick, 'reject', $checklist, 'Kurang foto', $qa);             // after 12h

    // Last month's decision stays out of this month's counts.
    $old = qaDashboardForm($project, ['created_at' => '2026-08-10 10:00:00']);
    $this->travelTo(Carbon::parse('2026-08-11 10:00:00'));
    $service->review($old, 'approve', $checklist, null, $qa);                       // after 24h
    $this->travelTo(Carbon::parse('2026-09-30 10:00:00'));

    expect(app(DivisionDashboardService::class)->qaStats())->toMatchArray([
        'approvedThisMonth' => 1,
        'rejectedThisMonth' => 2,
        'rejectionRate' => 67,
        'avgReviewHours' => 20.0,
        'reviewSample' => 3,
    ]);
});

test('forms rejected twice or more are listed, still-open ones first', function () {
    $project = Project::factory()->create();
    $approved = qaDashboardForm($project, ['status' => QaStatus::Approved->value, 'rejection_count' => 3, 'reviewed_at' => '2026-09-29 09:00:00']);
    $open = qaDashboardForm($project, ['status' => QaStatus::Rejected->value, 'rejection_count' => 2, 'reviewed_at' => '2026-09-28 09:00:00', 'notes' => 'Engsel longgar']);
    qaDashboardForm($project, ['status' => QaStatus::Rejected->value, 'rejection_count' => 1]);

    $repeat = app(DivisionDashboardService::class)->qaRepeatRejections();

    expect($repeat['total'])->toBe(2)
        ->and($repeat['rows']->pluck('id')->all())->toBe([$open->id, $approved->id])
        ->and($repeat['rows']->first()['notes'])->toBe('Engsel longgar');
});

// ── PRD §4.6: QA never sees task detail ──────────────────────────────────

test('the QA dashboard carries no task data at all', function () {
    $project = Project::factory()->create();
    $form = qaDashboardForm($project, ['rejection_count' => 2, 'status' => QaStatus::Pending->value]);
    Task::factory()->create([
        'project_id' => $project->id,
        'milestone_id' => $form->milestone_id,
        'title' => 'Pasang plafon gypsum RAHASIA',
        'description' => 'Detail pekerjaan tukang',
        'due_date' => '2026-09-01',
    ]);

    $response = $this->actingAs(qaDashboardUser('QA'))->get(route('qa-forms.dashboard'))->assertOk();

    $response->assertDontSee('Pasang plafon gypsum RAHASIA')->assertDontSee('Detail pekerjaan tukang');

    $props = json_decode(json_encode($response->viewData('page')['props']), true);
    $keys = collect(array_keys(Arr::dot(Arr::only($props, ['stats', 'pending', 'repeatRejections']))));
    expect($keys->filter(fn (string $key) => preg_match('/(^|\.)(tasks?|title|description|assignee\w*|due_?date|kendala)(\.|$)/i', $key)))->toBeEmpty();
    expect($props['pending'])->toHaveCount(1);
});
