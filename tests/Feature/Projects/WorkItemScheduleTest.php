<?php

use App\Enums\ProjectPhase;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectWorkItem;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Services\ActionInboxService;
use App\Services\WorkItemService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 35 — "Jadwal per Item" is the Timeline: the PM (or Admin Proyek)
 * puts RAB items into a timeline; an item's dates on the Lembar Progres are
 * its timelines' (earliest start → latest end, one block per timeline). It is
 * late once its last timeline ended below 100 % — queued for the PM and the
 * Pengawas.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', 'Asia/Jakarta'));
    $this->seed(RoleSeeder::class);

    foreach (['pm' => 'PM', 'qa' => 'QA', 'otherQa' => 'QA', 'admin' => 'ADMIN_PROYEK', 'logistics' => 'LOGISTICS'] as $key => $role) {
        $this->{$key} = User::factory()->create();
        $this->{$key}->assignRole($role);
    }

    $quotation = Quotation::factory()->create(['type' => 'PROYEK']);
    foreach (['Panel klinik', 'Panel gigi', 'Panel THT', 'Kabinet'] as $sort => $description) {
        QuotationItem::factory()->create(['quotation_id' => $quotation->id, 'description' => $description, 'qty' => 1, 'unit_price' => 10_000_000, 'total_price' => 10_000_000, 'sort_order' => $sort]);
    }
    $this->project = Project::factory()->create([
        'pm_id' => $this->pm->id, 'quotation_id' => $quotation->id, 'phase' => ProjectPhase::Produksi->value,
        'start_date' => '2026-09-25', 'end_date' => '2026-10-31', 'team_lead_name' => 'Putra',
    ]);
    $this->project->supervisors()->attach($this->qa->id);
    $service = app(WorkItemService::class);
    $service->ensureForProject($this->project);
    $this->project->workItems()->get()->each(fn (ProjectWorkItem $item) => $service->update($item, ['unit_wage' => 100_000], $this->qa));
});

afterEach(fn () => Carbon::setTestNow());

function scheduleItem(Project $project, string $description): ProjectWorkItem
{
    return $project->workItems()->whereHas('quotationItem', fn ($q) => $q->where('description', $description))->sole();
}

function timeline(object $test, string $name, string $start, string $end, array $items = []): Milestone
{
    $test->actingAs($test->pm)->post(route('milestones.store', $test->project), [
        'name' => $name, 'start_date' => $start, 'target_date' => $end,
        'work_item_ids' => collect($items)->map(fn (string $description) => scheduleItem($test->project, $description)->id)->all(),
    ])->assertSessionHasNoErrors();

    return $test->project->milestones()->where('name', $name)->sole();
}

test('the PM puts RAB items into a timeline; the items take its dates — several timelines = from the first start to the last end', function () {
    timeline($this, 'Produksi Panel', '2026-10-02', '2026-10-05', ['Panel klinik', 'Panel gigi']);
    $install = timeline($this, 'Pemasangan', '2026-10-20', '2026-10-31', ['Panel klinik']);

    expect(scheduleItem($this->project, 'Panel klinik')->production_start->toDateString())->toBe('2026-10-02')
        ->and(scheduleItem($this->project, 'Panel klinik')->production_end->toDateString())->toBe('2026-10-31')
        ->and(scheduleItem($this->project, 'Panel gigi')->production_end->toDateString())->toBe('2026-10-05')
        ->and(scheduleItem($this->project, 'Kabinet')->production_start)->toBeNull();

    // The sheet gets one block per timeline.
    $row = collect(app(WorkItemService::class)->scheduleOverview($this->project)['items'])->firstWhere('description', 'Panel klinik');
    expect(collect($row['timelines'])->pluck('name')->all())->toBe(['Produksi Panel', 'Pemasangan']);

    // Moving a timeline moves its items; removing the timeline takes its dates away.
    $this->actingAs($this->pm)->put(route('milestones.update', $install), ['name' => 'Pemasangan', 'start_date' => '2026-10-22', 'target_date' => '2026-10-30', 'status' => 'PENDING'])
        ->assertSessionHasNoErrors();
    expect(scheduleItem($this->project, 'Panel klinik')->production_end->toDateString())->toBe('2026-10-30');

    $this->actingAs($this->pm)->delete(route('milestones.destroy', $install))->assertSessionHasNoErrors();
    expect(scheduleItem($this->project, 'Panel klinik')->production_end->toDateString())->toBe('2026-10-05');
});

test('only this project items can be put in a timeline; the Pengawas and Logistik do not manage timelines', function () {
    $this->actingAs($this->pm)->post(route('milestones.store', $this->project), [
        'name' => 'Asing', 'start_date' => '2026-10-02', 'target_date' => '2026-10-05', 'work_item_ids' => [999999],
    ])->assertSessionHasErrors('work_item_ids');

    foreach ([$this->qa, $this->logistics] as $user) {
        $this->actingAs($user)->post(route('milestones.store', $this->project), ['name' => 'X', 'start_date' => '2026-10-02', 'target_date' => '2026-10-05'])->assertForbidden();
    }

    // The Admin Proyek inputs timelines too.
    $this->actingAs($this->admin)->post(route('milestones.store', $this->project), [
        'name' => 'Produksi Kabinet', 'start_date' => '2026-10-06', 'target_date' => '2026-10-09', 'work_item_ids' => [scheduleItem($this->project, 'Kabinet')->id],
    ])->assertSessionHasNoErrors();
    expect(scheduleItem($this->project, 'Kabinet')->production_start->toDateString())->toBe('2026-10-06');
});

test('late once its last timeline ended below 100 % — on the sheet and in the PM and Pengawas queue', function () {
    // Today is 14 Okt.
    timeline($this, 'Produksi Panel klinik', '2026-10-02', '2026-10-05', ['Panel klinik']);
    timeline($this, 'Pemasangan Panel klinik', '2026-10-20', '2026-10-31', ['Panel klinik']);
    timeline($this, 'Produksi Panel gigi', '2026-10-02', '2026-10-10', ['Panel gigi']);
    timeline($this, 'Produksi Panel THT', '2026-10-20', '2026-10-24', ['Panel THT']);
    // Kabinet: late too, but at 100 % → done.
    timeline($this, 'Produksi Kabinet', '2026-10-01', '2026-10-08', ['Kabinet']);
    $service = app(WorkItemService::class);
    $service->recordProgress($this->project, [['item_id' => scheduleItem($this->project, 'Kabinet')->id, 'percent' => 100]], null, $this->qa);

    $rows = collect($service->scheduleOverview($this->project)['items'])->keyBy('description');
    expect($rows['Panel klinik']['status'])->toBe(WorkItemService::SCHEDULE_RUNNING)
        ->and($rows['Panel gigi']['status'])->toBe(WorkItemService::SCHEDULE_LATE)
        ->and($rows['Panel THT']['status'])->toBe(WorkItemService::SCHEDULE_UPCOMING)
        ->and($rows['Kabinet']['status'])->toBe(WorkItemService::SCHEDULE_DONE);

    $inbox = fn (User $user, string $key) => collect(app(ActionInboxService::class)->for($user, fresh: true))->firstWhere('key', $key);
    expect($inbox($this->pm, 'items-late-pm')['items'][0]['subtitle'])->toBe('1 item terlambat')
        ->and($inbox($this->qa, 'items-late-supervisor')['items'][0]['subtitle'])->toBe('1 item terlambat')
        ->and($inbox($this->otherQa, 'items-late-supervisor'))->toBeNull();

    // Every viewer of the tab reads it; whoever manages the timelines links the items (the PM, not the Pengawas).
    $this->actingAs($this->logistics)->get(route('projects.show', $this->project))->assertInertia(fn (Assert $page) => $page
        ->where('execution.workSchedule.summary.late', 1)
        ->where('execution.can.linkTimelines', false));
    $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertInertia(fn (Assert $page) => $page
        ->where('execution.workSchedule.summary.total', 4)
        ->where('execution.can.linkTimelines', true)
        ->has('timelineItems', 4)
        ->where('milestones.0.work_items.0.id', scheduleItem($this->project, 'Panel klinik')->id));
});
