<?php

use App\Enums\MilestoneStatus;
use App\Enums\ProjectPhase;
use App\Enums\ProjectStatus;
use App\Enums\SiteFindingStatus;
use App\Enums\WorkItemQaStatus;
use App\Models\Milestone;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectWorkItem;
use App\Models\QaForm;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\SiteFinding;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkItemQaCheck;
use App\Services\ActionInboxService;
use App\Services\Kpi\KpiMetricRegistry;
use App\Services\MilestoneService;
use App\Services\ProjectService;
use App\Services\WorkItemQaService;
use App\Services\WorkItemService;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 29 Sub 12 (G1–G3) — "QA per item RAB": an item with a wage that
 * reaches 100 % waits for the Pengawas's QA (Lolos / Perlu perbaikan → a
 * finding, QA again once repaired); the project completes when every item
 * passed; "Tandai Selesai" timeline no longer makes a QA form. Projects whose
 * timelines already went through QA keep the old flow.
 */

beforeEach(function () {
    // Wednesday, a working day.
    Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', 'Asia/Jakarta'));
    $this->seed(RoleSeeder::class);
    $this->ceo = itemQaUser('CEO');
    $this->pm = itemQaUser('PM');
    $this->qa = itemQaUser('QA');
    $this->otherQa = itemQaUser('QA');
    $this->admin = itemQaUser('ADMIN_PROYEK');
    $this->marketing = itemQaUser('MARKETING');
    $this->tukang = itemQaUser('FIELD_STAFF');
});

afterEach(fn () => Carbon::setTestNow());

function itemQaUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** A producing project of $test->pm (Pengawas $test->qa): three RAB items with a wage + one without. */
function itemQaProject(object $test): Project
{
    $quotation = Quotation::factory()->create(['type' => 'PROYEK']);

    foreach (['Kitchen set', 'Lemari', 'Plafon', 'Barang jadi'] as $sort => $description) {
        QuotationItem::factory()->create([
            'quotation_id' => $quotation->id, 'description' => $description, 'qty' => 1,
            'unit_price' => 10_000_000, 'total_price' => 10_000_000, 'sort_order' => $sort,
        ]);
    }

    $project = Project::factory()->create([
        'pm_id' => $test->pm->id, 'quotation_id' => $quotation->id, 'phase' => ProjectPhase::Produksi->value,
        'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'team_lead_name' => 'Putra',
    ]);
    $project->supervisors()->attach($test->qa->id);

    $service = app(WorkItemService::class);
    $service->ensureForProject($project);
    $project->workItems()->with('quotationItem')->get()
        ->reject(fn (ProjectWorkItem $item) => $item->quotationItem->description === 'Barang jadi')
        ->each(fn (ProjectWorkItem $item) => $service->update($item, ['unit_wage' => 100_000], $test->qa));

    return $project->fresh();
}

function qaItem(Project $project, string $description): ProjectWorkItem
{
    return $project->workItems()->whereHas('quotationItem', fn ($q) => $q->where('description', $description))->sole();
}

/** @param  array<string, float|int>  $percents  uraian => % added */
function itemQaCheck(Project $project, array $percents, User $actor): void
{
    app(WorkItemService::class)->recordProgress(
        $project,
        collect($percents)->map(fn ($percent, $description) => ['item_id' => qaItem($project, $description)->id, 'percent' => $percent])->values()->all(),
        null,
        $actor,
    );
}

function itemQaStatus(Project $project, string $description): WorkItemQaStatus
{
    return app(WorkItemQaService::class)->statuses($project)->firstWhere(fn (array $row) => $row['item']->quotationItem->description === $description)['status'];
}

function itemQaInbox(User $user): ?array
{
    return collect(app(ActionInboxService::class)->for($user, fresh: true))->firstWhere('key', 'item-qa-pending');
}

test('an item at 100 % waits for its Pengawas\'s QA; items below stay out of the queue', function () {
    $project = itemQaProject($this);
    expect($project->usesItemQa())->toBeTrue();

    itemQaCheck($project, ['Kitchen set' => 100, 'Lemari' => 60], $this->qa);

    expect(itemQaStatus($project, 'Kitchen set'))->toBe(WorkItemQaStatus::Menunggu)
        ->and(itemQaStatus($project, 'Lemari'))->toBe(WorkItemQaStatus::Belum)
        ->and(itemQaStatus($project, 'Plafon'))->toBe(WorkItemQaStatus::Belum);

    $group = itemQaInbox($this->qa);
    expect($group['count'])->toBe(1)
        ->and($group['items'][0]['title'])->toBe($project->name)
        ->and($group['items'][0]['subtitle'])->toBe('1 item menunggu QA');
    // Only the project's Pengawas.
    expect(itemQaInbox($this->otherQa))->toBeNull();

    // An item under 100 % can't be passed.
    $this->actingAs($this->qa)
        ->post(route('projects.item-qa.decide', $project), ['decision' => 'LOLOS', 'item_ids' => [qaItem($project, 'Lemari')->id]])
        ->assertSessionHasErrors('item_ids');
});

test('the Pengawas passes several items at once; the decision is append-only', function () {
    $project = itemQaProject($this);
    itemQaCheck($project, ['Kitchen set' => 100, 'Lemari' => 100, 'Plafon' => 40], $this->qa);

    $this->actingAs($this->qa)
        ->post(route('projects.item-qa.decide', $project), [
            'decision' => 'LOLOS',
            'item_ids' => [qaItem($project, 'Kitchen set')->id, qaItem($project, 'Lemari')->id],
            'note' => 'Rapi',
        ])
        ->assertSessionHasNoErrors();

    expect(WorkItemQaCheck::count())->toBe(2)
        ->and(itemQaStatus($project, 'Kitchen set'))->toBe(WorkItemQaStatus::Lolos)
        ->and(itemQaStatus($project, 'Lemari'))->toBe(WorkItemQaStatus::Lolos)
        ->and(itemQaInbox($this->qa))->toBeNull()
        ->and(Notification::where('user_id', $this->pm->id)->where('type', 'work_item_qa_decided')->count())->toBe(1);

    // Passing it twice is refused.
    $this->actingAs($this->qa)
        ->post(route('projects.item-qa.decide', $project), ['decision' => 'LOLOS', 'item_ids' => [qaItem($project, 'Kitchen set')->id]])
        ->assertSessionHasErrors('item_ids');

    $check = WorkItemQaCheck::first();
    expect(fn () => $check->update(['note' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $check->delete())->toThrow(LogicException::class);

    // Two of three items passed — the project still runs.
    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('Perlu perbaikan opens a finding; once repaired the item waits for QA again', function () {
    $project = itemQaProject($this);
    itemQaCheck($project, ['Kitchen set' => 100], $this->qa);
    $item = qaItem($project, 'Kitchen set');

    // A note is required, one item at a time.
    $this->actingAs($this->qa)
        ->post(route('projects.item-qa.decide', $project), ['decision' => 'PERBAIKAN', 'item_ids' => [$item->id]])
        ->assertSessionHasErrors('note');

    $this->actingAs($this->qa)
        ->post(route('projects.item-qa.decide', $project), [
            'decision' => 'PERBAIKAN', 'item_ids' => [$item->id], 'note' => 'Engsel pintu atas miring', 'photo_url' => 'https://drive.google.com/f',
        ])
        ->assertSessionHasNoErrors();

    $finding = SiteFinding::sole();
    expect($finding->description)->toContain('Kitchen set')->toContain('Engsel pintu atas miring')
        ->and($finding->photo_url)->toBe('https://drive.google.com/f')
        ->and(WorkItemQaCheck::sole()->site_finding_id)->toBe($finding->id)
        ->and(itemQaStatus($project, 'Kitchen set'))->toBe(WorkItemQaStatus::Perbaikan)
        ->and(itemQaInbox($this->qa))->toBeNull();

    // "Jadikan Task Perbaikan" → the Tukang finishes → the Pengawas approves it → the finding closes.
    $this->actingAs($this->qa)->post(route('site-findings.repair-task', $finding), ['assignee_id' => $this->tukang->id, 'due_date' => '2026-10-15'])->assertSessionHasNoErrors();
    $repair = Task::sole();
    $this->actingAs($this->tukang)->patch(route('tasks.updateStatus', $repair), ['status' => 'DONE'])->assertSessionHasNoErrors();
    $this->actingAs($this->qa)->post(route('tasks.approve', $repair))->assertSessionHasNoErrors();

    expect($finding->fresh()->status)->toBe(SiteFindingStatus::Selesai)
        ->and(itemQaStatus($project, 'Kitchen set'))->toBe(WorkItemQaStatus::Menunggu)
        ->and(itemQaInbox($this->qa)['count'])->toBe(1);

    $this->actingAs($this->qa)
        ->post(route('projects.item-qa.decide', $project), ['decision' => 'LOLOS', 'item_ids' => [$item->id]])
        ->assertSessionHasNoErrors();
    expect(itemQaStatus($project, 'Kitchen set'))->toBe(WorkItemQaStatus::Lolos);
});

test('the project is COMPLETED only once every item with a wage passed and no finding is open', function () {
    $project = itemQaProject($this);
    // A timeline still open does not matter with QA per item (G2).
    Milestone::factory()->create(['project_id' => $project->id, 'status' => MilestoneStatus::InProgress->value]);
    $unrelated = SiteFinding::create(['project_id' => $project->id, 'description' => 'Lis plafon miring', 'status' => 'TERBUKA', 'reported_by' => $this->qa->id]);

    itemQaCheck($project, ['Kitchen set' => 100, 'Lemari' => 100, 'Plafon' => 100], $this->qa);
    $ids = $project->workItems()->where('unit_wage', '>', 0)->pluck('id')->all();

    $this->actingAs($this->qa)->post(route('projects.item-qa.decide', $project), ['decision' => 'LOLOS', 'item_ids' => $ids])->assertSessionHasNoErrors();

    // The open finding holds it.
    expect(app(WorkItemQaService::class)->allPassed($project))->toBeTrue()
        ->and($project->fresh()->status)->toBe(ProjectStatus::Active);

    // Closing the last finding completes it.
    $this->actingAs($this->pm)->post(route('site-findings.resolve', $unrelated))->assertSessionHasNoErrors();
    expect($project->fresh()->status)->toBe(ProjectStatus::Completed)
        ->and(Notification::where('user_id', $this->ceo->id)->where('type', 'project_completed')->value('message'))->toContain('Semua item RAB');
});

test('only the project\'s Pengawas decides QA per item; the QC Item view and photo follow the Timeline & QC roles', function () {
    Storage::fake('local');
    $project = itemQaProject($this);
    itemQaCheck($project, ['Kitchen set' => 100, 'Lemari' => 100], $this->qa);
    $payload = ['decision' => 'LOLOS', 'item_ids' => [qaItem($project, 'Kitchen set')->id]];

    foreach ([$this->otherQa, $this->admin, $this->pm, $this->marketing] as $user) {
        $this->actingAs($user)->post(route('projects.item-qa.decide', $project), $payload)->assertForbidden();
    }

    // A second Pengawas (Sub 06) is equal.
    $project->supervisors()->attach($this->otherQa->id);
    $this->actingAs($this->otherQa)->post(route('projects.item-qa.decide', $project), $payload)->assertSessionHasNoErrors();

    // A photo from the phone camera: re-encoded WebP on the private disk.
    $this->actingAs($this->qa)
        ->post(route('projects.item-qa.decide', $project), [
            'decision' => 'PERBAIKAN', 'item_ids' => [qaItem($project, 'Lemari')->id], 'note' => 'Pintu tidak rata',
            'photo' => UploadedFile::fake()->image('kamera.jpg', 1200, 900),
        ])
        ->assertSessionHasNoErrors();
    $check = WorkItemQaCheck::whereNotNull('photo_path')->sole();
    Storage::disk('local')->assertExists($check->photo_path);
    expect($check->photo_path)->toEndWith('.webp');

    $photo = route('projects.item-qa.photo', ['project' => $project->id, 'check' => $check->id]);
    $this->actingAs($this->admin)->get($photo)->assertOk()->assertHeader('Content-Type', 'image/webp');
    $this->actingAs($this->marketing)->get($photo)->assertForbidden();
    $this->actingAs($this->tukang)->get($photo)->assertForbidden();

    // Detail Proyek: QC Item with the Pengawas's buttons; nothing for Marketing; no photo path leaks.
    $this->actingAs($this->qa)->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page
            ->where('itemQa.summary.total', 3)
            ->where('itemQa.summary.lolos', 1)
            ->where('itemQa.summary.perbaikan', 1)
            ->where('itemQa.can.decide', true)
            ->missing('itemQa.items.1.latest.photo_path')
            ->where('itemQa.items.1.latest.photo_href', $photo));
    $this->actingAs($this->admin)->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page->where('itemQa.can.decide', false));
    $this->actingAs($this->marketing)->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page->where('itemQa', null));
});

test('the Pengawas\'s KPI "form QA diproses" counts every item decided', function () {
    $project = itemQaProject($this);
    itemQaCheck($project, ['Kitchen set' => 100, 'Lemari' => 100], $this->qa);
    $service = app(WorkItemQaService::class);
    $service->decide($project, ['decision' => 'LOLOS', 'item_ids' => [qaItem($project, 'Kitchen set')->id]], null, $this->qa);
    $service->decide($project, ['decision' => 'PERBAIKAN', 'item_ids' => [qaItem($project, 'Lemari')->id], 'note' => 'Pintu miring'], null, $this->qa);

    $month = now()->startOfMonth();
    expect(app(KpiMetricRegistry::class)->compute('qa_forms_processed_count', $this->qa, $month, $month->copy()->addMonth()))->toBe(2.0);
});

test('"Tandai Selesai" timeline makes no QA form with QA per item; older projects keep the timeline QA', function () {
    $project = itemQaProject($this);
    $first = Milestone::factory()->create(['project_id' => $project->id, 'order' => 0, 'status' => MilestoneStatus::InProgress->value]);
    $second = Milestone::factory()->create(['project_id' => $project->id, 'order' => 1, 'status' => MilestoneStatus::Pending->value]);

    $this->actingAs($this->pm)->post(route('milestones.markDone', $first))->assertSessionHasNoErrors()->assertSessionHas('success', 'Timeline ditandai selesai.');
    expect($first->fresh()->status)->toBe(MilestoneStatus::Completed)
        ->and($second->fresh()->status)->toBe(MilestoneStatus::InProgress)
        ->and(QaForm::count())->toBe(0);

    // An older project: its timelines already went through QA → stays per timeline, even with wages.
    $older = itemQaProject($this);
    $done = Milestone::factory()->create(['project_id' => $older->id, 'order' => 0, 'status' => MilestoneStatus::Completed->value]);
    QaForm::create(['project_id' => $older->id, 'milestone_id' => $done->id, 'status' => 'APPROVED', 'checklist_data' => []]);
    $next = Milestone::factory()->create(['project_id' => $older->id, 'order' => 1, 'status' => MilestoneStatus::InProgress->value]);

    expect($older->usesItemQa())->toBeFalse();
    app(MilestoneService::class)->markDone($next);
    expect($next->fresh()->status)->toBe(MilestoneStatus::QaWaiting)
        ->and(QaForm::where('milestone_id', $next->id)->exists())->toBeTrue();

    // …and completes on its timelines, never on items.
    itemQaCheck($older, ['Kitchen set' => 100], $this->qa);
    expect(itemQaInbox($this->qa))->toBeNull()
        ->and(app(ProjectService::class)->completeIfFinished($older))->toBeFalse();
});
