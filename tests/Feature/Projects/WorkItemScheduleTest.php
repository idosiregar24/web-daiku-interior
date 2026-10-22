<?php

use App\Enums\ProjectPhase;
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
 * Sprint 31 Sub 03 — "Jadwal per Item" (File C, the light-blue cells): the
 * Pengawas / Admin Proyek give each RAB item a production window and,
 * optionally, an installation window; the item is late once its last window
 * ended below 100 % — shown on the card and queued for the PM and the Pengawas.
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

test('the Pengawas and the Admin Proyek set an item\'s windows; the PM, another QA and Logistik may not', function () {
    $item = scheduleItem($this->project, 'Panel klinik');
    // File C row 4: production 2–5 Okt, the second block 20–31 Okt.
    $schedule = ['production_start' => '2026-10-02', 'production_end' => '2026-10-05', 'installation_start' => '2026-10-20', 'installation_end' => '2026-10-31'];

    foreach ([$this->pm, $this->otherQa, $this->logistics] as $user) {
        $this->actingAs($user)->put(route('projects.work-items.schedule', $item), $schedule)->assertForbidden();
    }

    $this->actingAs($this->qa)->put(route('projects.work-items.schedule', $item), $schedule)->assertSessionHasNoErrors();
    expect($item->fresh()->production_start->toDateString())->toBe('2026-10-02')
        ->and($item->fresh()->installation_end->toDateString())->toBe('2026-10-31');

    // The Admin Proyek inputs too; the production window alone is enough.
    $this->actingAs($this->admin)->put(route('projects.work-items.schedule', scheduleItem($this->project, 'Kabinet')), ['production_start' => '2026-10-10', 'production_end' => '2026-10-20'])
        ->assertSessionHasNoErrors();
});

test('a schedule stays inside the project, ends after it starts, and installation never starts before production', function () {
    $item = scheduleItem($this->project, 'Panel klinik');
    $put = fn (array $data) => $this->actingAs($this->qa)->put(route('projects.work-items.schedule', $item), $data);

    $put(['production_start' => '2026-10-05', 'production_end' => '2026-10-02'])->assertSessionHasErrors('production_end');
    $put(['production_start' => '2026-10-05'])->assertSessionHasErrors('production_end');
    $put(['production_start' => '2026-09-20', 'production_end' => '2026-09-28'])->assertSessionHasErrors('production_start');
    $put(['production_start' => '2026-10-28', 'production_end' => '2026-11-05'])->assertSessionHasErrors('production_end');
    $put(['production_start' => '2026-10-10', 'production_end' => '2026-10-12', 'installation_start' => '2026-10-05', 'installation_end' => '2026-10-08'])
        ->assertSessionHasErrors('installation_start');

    expect($item->fresh()->production_start)->toBeNull();
});

test('late once the last window ended below 100 % — on the card and in the PM\'s and the Pengawas\'s queue', function () {
    $service = app(WorkItemService::class);
    $set = fn (string $description, array $data) => $service->updateSchedule(scheduleItem($this->project, $description), $data, $this->qa);

    // Today is 14 Okt.
    $set('Panel klinik', ['production_start' => '2026-10-02', 'production_end' => '2026-10-05', 'installation_start' => '2026-10-20', 'installation_end' => '2026-10-31']);
    $set('Panel gigi', ['production_start' => '2026-10-02', 'production_end' => '2026-10-10']);
    $set('Panel THT', ['production_start' => '2026-10-20', 'production_end' => '2026-10-24']);
    // Kabinet: late window too, but at 100 % → done.
    $set('Kabinet', ['production_start' => '2026-10-01', 'production_end' => '2026-10-08']);
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

    // The Eksekusi tab: every viewer reads it, only the Pengawas / Admin Proyek edit.
    $this->actingAs($this->logistics)->get(route('projects.show', $this->project))->assertInertia(fn (Assert $page) => $page
        ->where('execution.workSchedule.summary.late', 1)
        ->where('execution.can.scheduleItems', false));
    $this->actingAs($this->qa)->get(route('projects.show', $this->project))->assertInertia(fn (Assert $page) => $page
        ->where('execution.workSchedule.summary.total', 4)
        ->where('execution.can.scheduleItems', true));
});

test('"Jadwalkan berurutan": File C\'s staircase — each item one day after the previous, one refused item saves none', function () {
    $ids = $this->project->workItems()->orderBy('id')->take(3)->pluck('id')->all();
    $payload = [
        'item_ids' => $ids, 'production_start' => '2026-10-02', 'production_days' => 4, 'gap_days' => 1,
        'installation_start' => '2026-10-20', 'installation_days' => 3,
    ];

    $this->actingAs($this->pm)->post(route('projects.work-items.scheduleSequence', $this->project), $payload)->assertForbidden();
    $this->actingAs($this->qa)->post(route('projects.work-items.scheduleSequence', $this->project), $payload)->assertSessionHasNoErrors();

    $windows = ProjectWorkItem::whereKey($ids)->orderBy('id')->get()->map(fn (ProjectWorkItem $item) => [
        $item->production_start->toDateString(), $item->production_end->toDateString(),
        $item->installation_start->toDateString(), $item->installation_end->toDateString(),
    ])->all();
    expect($windows)->toBe([
        ['2026-10-02', '2026-10-05', '2026-10-20', '2026-10-22'],
        ['2026-10-03', '2026-10-06', '2026-10-21', '2026-10-23'],
        ['2026-10-04', '2026-10-07', '2026-10-22', '2026-10-24'],
    ]);

    // Past the project's end (31 Okt) for the third item → refused, naming it; nothing changes.
    $this->actingAs($this->qa)->post(route('projects.work-items.scheduleSequence', $this->project), [...$payload, 'production_start' => '2026-10-27', 'installation_start' => null, 'installation_days' => null])
        ->assertSessionHasErrors('production_start');
    expect(ProjectWorkItem::find($ids[0])->production_start->toDateString())->toBe('2026-10-02');
});
