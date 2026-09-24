<?php

use App\Enums\LeadStatus;
use App\Enums\MilestoneStatus;
use App\Enums\TaskStatus;
use App\Models\FamilyGatheringFund;
use App\Models\Lead;
use App\Models\Material;
use App\Models\Milestone;
use App\Models\Penalty;
use App\Models\PipelineLog;
use App\Models\Project;
use App\Models\QaForm;
use App\Models\Quotation;
use App\Models\RevenueTarget;
use App\Models\Task;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\StockService;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function analyticsUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

// ── RBAC: PRD §7.1 "Analytics – Executive" = CEO only ────────────────────

test('the CEO can open the executive dashboard with every widget', function () {
    $this->actingAs(analyticsUser('CEO'))->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Analytics/Index')
            ->has('funnel.stages', 4)
            ->has('activeProjects')
            ->has('revenue', 6)
            ->has('cashFlow', 6)
            ->has('teamPerformance')
            ->has('penalties')
            ->has('materialMargin')
            ->has('overdueHeatmap.columns', 8));
});

test('every other role is refused the executive dashboard', function (string $role) {
    $this->actingAs(analyticsUser($role))->get(route('analytics.index'))->assertForbidden();
    $this->actingAs(analyticsUser($role))->post(route('analytics.targets.store'), ['month' => '2026-09', 'target_amount' => 1])->assertForbidden();
})->with(['MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'QA', 'FINANCE', 'LOGISTICS', 'FIELD_STAFF']);

// ── Revenue targets ──────────────────────────────────────────────────────

test('the CEO sets a monthly target and re-saving overwrites it', function () {
    $ceo = analyticsUser('CEO');

    $this->actingAs($ceo)->post(route('analytics.targets.store'), ['month' => '2026-09', 'target_amount' => 500_000_000])->assertRedirect();
    $this->actingAs($ceo)->post(route('analytics.targets.store'), ['month' => '2026-09', 'target_amount' => 650_000_000]);

    expect(RevenueTarget::count())->toBe(1)
        ->and((float) RevenueTarget::sole()->target_amount)->toBe(650_000_000.0);
});

test('a target month must be YYYY-MM', function () {
    $this->actingAs(analyticsUser('CEO'))
        ->post(route('analytics.targets.store'), ['month' => 'September', 'target_amount' => 1])
        ->assertSessionHasErrors('month');
});

test('revenue vs target sums closed contract value per month beside its target', function () {
    Project::factory()->create(['contract_value' => 100_000_000]);
    Project::factory()->create(['contract_value' => 50_000_000]);
    Project::factory()->create(['contract_value' => 999, 'created_at' => now()->subMonths(8)]); // outside the window
    RevenueTarget::create(['month' => now()->format('Y-m'), 'target_amount' => 200_000_000, 'set_by' => analyticsUser('CEO')->id]);

    $current = app(AnalyticsService::class)->revenueVsTarget()->last();

    expect($current['revenue'])->toBe(150_000_000.0)
        ->and($current['target'])->toBe(200_000_000.0);
});

// ── Widgets ──────────────────────────────────────────────────────────────

test('the funnel counts leads cumulatively, including ones lost after reaching a stage', function () {
    Lead::factory()->count(2)->create(['status' => LeadStatus::FollowUp->value]);
    $lostAfterDeal = Lead::factory()->create(['status' => LeadStatus::Lost->value, 'lost_reason' => 'Budget']);
    PipelineLog::create(['lead_id' => $lostAfterDeal->id, 'from_status' => 'FOLLOW_UP', 'to_status' => 'DEAL_DESAIN', 'changed_by' => analyticsUser('MARKETING')->id]);
    $closed = Lead::factory()->create(['status' => LeadStatus::Closing->value]);
    Quotation::factory()->approved()->create(['lead_id' => $closed->id]);

    $funnel = app(AnalyticsService::class)->pipelineFunnel();

    expect(collect($funnel['stages'])->pluck('count')->all())->toBe([4, 2, 1, 1])
        ->and($funnel['lost'])->toBe(1)
        ->and($funnel['conversionRate'])->toBe(25.0);
});

test('PM on-time rate counts milestones approved by QA on or before their target date', function () {
    $pm = analyticsUser('PM');
    $project = Project::factory()->create(['pm_id' => $pm->id]);
    $onTime = Milestone::factory()->create(['project_id' => $project->id, 'status' => MilestoneStatus::Completed->value, 'target_date' => now()->addDays(2)]);
    $late = Milestone::factory()->create(['project_id' => $project->id, 'status' => MilestoneStatus::Completed->value, 'target_date' => now()->subDays(5)]);
    QaForm::factory()->create(['milestone_id' => $onTime->id, 'project_id' => $project->id, 'status' => 'APPROVED', 'reviewed_at' => now()]);
    QaForm::factory()->create(['milestone_id' => $late->id, 'project_id' => $project->id, 'status' => 'APPROVED', 'reviewed_at' => now()]);

    $row = app(AnalyticsService::class)->teamPerformance()->firstWhere('id', $pm->id);

    expect($row['milestonesCompleted'])->toBe(2)
        ->and($row['onTimeRate'])->toEqual(50)
        ->and($row['activeProjects'])->toBe(1);
});

test('the overdue heatmap buckets overdue tasks of active projects by due week', function () {
    $project = Project::factory()->create();
    Task::factory()->create(['project_id' => $project->id, 'due_date' => now()->subDay(), 'status' => TaskStatus::Over->value]);
    Task::factory()->create(['project_id' => $project->id, 'due_date' => now()->subWeeks(20), 'status' => TaskStatus::OnProgress->value]);
    Task::factory()->create(['project_id' => $project->id, 'due_date' => now()->subDay(), 'status' => TaskStatus::Done->value]);
    $closed = Project::factory()->create(['status' => 'COMPLETED']);
    Task::factory()->create(['project_id' => $closed->id, 'due_date' => now()->subDay()]);

    $heatmap = app(AnalyticsService::class)->overdueHeatmap();
    $row = $heatmap['rows'][0];

    expect($heatmap['rows'])->toHaveCount(1)
        ->and($row['total'])->toBe(2)
        // A 20-week-old task folds into the first ("≤") column instead of disappearing.
        ->and($row['cells'][0])->toBe(1)
        ->and(array_sum($row['cells']))->toBe(2);
});

test('penalty summary reports this month and the fund balance', function () {
    Penalty::factory()->create(['amount' => 50000, 'date_occurred' => now()]);
    Penalty::factory()->create(['amount' => 50000, 'date_occurred' => now()->subMonths(2)]);
    FamilyGatheringFund::factory()->create(['type' => 'INCOME', 'amount' => 100000]);
    FamilyGatheringFund::factory()->create(['type' => 'EXPENSE', 'amount' => 30000]);

    $summary = app(AnalyticsService::class)->penaltySummary();

    expect($summary['monthTotal'])->toBe(50000.0)
        ->and($summary['monthCount'])->toBe(1)
        ->and($summary['allTimeTotal'])->toBe(100000.0)
        ->and($summary['fundBalance'])->toBe(70000.0);
});

test('material margin separates realized usage from stock potential', function () {
    $material = Material::factory()->create(['cost_price' => 100, 'sell_price' => 150, 'stock' => 10]);
    app(StockService::class)->stockOut($material, Project::factory()->create(), ['qty' => 4], analyticsUser('LOGISTICS'));

    $margin = app(AnalyticsService::class)->materialMargin();

    expect($margin['realized'])->toBe(200.0)   // 4 × 50
        ->and($margin['potential'])->toBe(300.0) // 6 left × 50
        ->and($margin['topMaterials'][0]['qtyUsed'])->toBe(4);
});
