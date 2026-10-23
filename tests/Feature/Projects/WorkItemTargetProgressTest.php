<?php

use App\Enums\ProjectPhase;
use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectWorkItem;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\SiteFinding;
use App\Models\Task;
use App\Models\User;
use App\Services\WorkItemService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 38 — a daily target may work on one RAB item of its timeline; the
 * item's "Progres Target" = its targets done ÷ all its targets. A reference
 * beside the Pengawas's pengecekan — never the money, never the progress.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', 'Asia/Jakarta'));
    $this->seed(RoleSeeder::class);

    foreach (['pm' => 'PM', 'qa' => 'QA', 'otherQa' => 'QA', 'admin' => 'ADMIN_PROYEK', 'logistics' => 'LOGISTICS', 'tukang' => 'FIELD_STAFF'] as $key => $role) {
        $this->{$key} = User::factory()->create();
        $this->{$key}->assignRole($role);
    }

    // Panel klinik & Panel gigi 10 jt each, Kabinet 20 jt → bobot 25 / 25 / 50; Plafon without a wage.
    $quotation = Quotation::factory()->create(['type' => 'PROYEK']);
    foreach (['Panel klinik' => 10_000_000, 'Panel gigi' => 10_000_000, 'Kabinet' => 20_000_000, 'Plafon' => 5_000_000] as $description => $price) {
        QuotationItem::factory()->create(['quotation_id' => $quotation->id, 'description' => $description, 'qty' => 1, 'unit_price' => $price, 'total_price' => $price]);
    }
    $this->project = Project::factory()->create([
        'pm_id' => $this->pm->id, 'quotation_id' => $quotation->id, 'phase' => ProjectPhase::Produksi->value,
        'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'team_lead_name' => 'Putra',
    ]);
    $this->project->supervisors()->attach($this->qa->id);
    $service = app(WorkItemService::class);
    $service->ensureForProject($this->project);
    $this->project->workItems()->with('quotationItem')->get()
        ->reject(fn (ProjectWorkItem $item) => $item->quotationItem->description === 'Plafon')
        ->each(fn (ProjectWorkItem $item) => $service->update($item, ['unit_wage' => 100_000], $this->qa));

    // "Produksi Panel" holds one item with a wage (+ Plafon, without); "Produksi Kabinet & Panel" two.
    $this->panelTimeline = targetTimeline($this, 'Produksi Panel', ['Panel klinik', 'Plafon']);
    $this->mixedTimeline = targetTimeline($this, 'Produksi Kabinet & Panel', ['Kabinet', 'Panel gigi']);
});

afterEach(fn () => Carbon::setTestNow());

function targetItem(Project $project, string $description): ProjectWorkItem
{
    return $project->workItems()->whereHas('quotationItem', fn ($q) => $q->where('description', $description))->sole();
}

function targetTimeline(object $test, string $name, array $items): Milestone
{
    $test->actingAs($test->pm)->post(route('milestones.store', $test->project), [
        'name' => $name, 'start_date' => '2026-10-01', 'target_date' => '2026-10-31',
        'work_item_ids' => collect($items)->map(fn (string $description) => targetItem($test->project, $description)->id)->all(),
    ])->assertSessionHasNoErrors();

    return $test->project->milestones()->where('name', $name)->sole();
}

function storeTarget(object $test, Milestone $milestone, array $extra = [], ?User $actor = null)
{
    return $test->actingAs($actor ?? $test->qa)->post(route('tasks.store', $test->project), [
        'milestone_id' => $milestone->id,
        'title' => 'Target '.fake()->unique()->numberBetween(1, 99999),
        'assignee_id' => $test->tukang->id,
        'due_date' => '2026-10-14',
        ...$extra,
    ]);
}

/** A target straight in the database (counting tests). */
function plantTarget(object $test, string $item, TaskStatus $status, ?string $completedAt = null): Task
{
    return Task::create([
        'project_id' => $test->project->id,
        'milestone_id' => $test->mixedTimeline->id,
        'project_work_item_id' => targetItem($test->project, $item)->id,
        'title' => 'Target',
        'assignee_id' => $test->tukang->id,
        'created_by' => $test->qa->id,
        'due_date' => '2026-10-14',
        'planned_date' => '2026-10-14',
        'status' => $status->value,
        'completed_at' => $completedAt,
    ]);
}

test('a new target takes its timeline\'s only item with a wage; a timeline with several leaves it to the Pengawas', function () {
    storeTarget($this, $this->panelTimeline)->assertSessionHasNoErrors();
    expect(Task::latest('id')->first()->project_work_item_id)->toBe(targetItem($this->project, 'Panel klinik')->id);

    storeTarget($this, $this->mixedTimeline)->assertSessionHasNoErrors();
    expect(Task::latest('id')->first()->project_work_item_id)->toBeNull();

    storeTarget($this, $this->mixedTimeline, ['project_work_item_id' => targetItem($this->project, 'Kabinet')->id])->assertSessionHasNoErrors();
    expect(Task::latest('id')->first()->project_work_item_id)->toBe(targetItem($this->project, 'Kabinet')->id);

    // "— Tanpa item" sent explicitly stays empty, even with one item with a wage.
    storeTarget($this, $this->panelTimeline, ['project_work_item_id' => null])->assertSessionHasNoErrors();
    expect(Task::latest('id')->first()->project_work_item_id)->toBeNull();
});

test('the item must be one of the target\'s timeline — and of this project', function () {
    storeTarget($this, $this->panelTimeline, ['project_work_item_id' => targetItem($this->project, 'Kabinet')->id])
        ->assertSessionHasErrors(['project_work_item_id' => 'Item RAB harus salah satu item di timeline "Produksi Panel".']);

    $other = Project::factory()->create();
    $foreign = ProjectWorkItem::create(['project_id' => $other->id, 'quotation_item_id' => QuotationItem::factory()->create()->id]);
    storeTarget($this, $this->panelTimeline, ['project_work_item_id' => $foreign->id])->assertSessionHasErrors('project_work_item_id');

    expect(Task::count())->toBe(0);
});

test('several tukang × days at once all get the item', function () {
    $other = User::factory()->create();
    $other->assignRole('FIELD_STAFF');

    $this->actingAs($this->qa)->post(route('tasks.storeMany', $this->project), [
        'milestone_id' => $this->mixedTimeline->id,
        'project_work_item_id' => targetItem($this->project, 'Panel gigi')->id,
        'title' => 'Pasang panel gigi',
        'assignee_ids' => [$this->tukang->id, $other->id],
        'date_from' => '2026-10-14',
        'date_to' => '2026-10-15',
    ])->assertSessionHasNoErrors();

    expect(Task::count())->toBe(4)
        ->and(Task::pluck('project_work_item_id')->unique()->all())->toBe([targetItem($this->project, 'Panel gigi')->id]);
});

test('moving a target to another timeline keeps an item it still holds, else picks again; a timeline dropping the item clears it', function () {
    storeTarget($this, $this->mixedTimeline, ['project_work_item_id' => targetItem($this->project, 'Kabinet')->id]);
    $task = Task::sole();
    $edit = fn (array $data) => $this->actingAs($this->qa)->put(route('tasks.update', $task), [
        'title' => $task->title, 'due_date' => '2026-10-14', 'priority' => 'MEDIUM', 'assignee_id' => $this->tukang->id, ...$data,
    ]);

    // Left out (Tugas page) on the same timeline → kept.
    $edit(['milestone_id' => $this->mixedTimeline->id])->assertSessionHasNoErrors();
    expect($task->fresh()->project_work_item_id)->toBe(targetItem($this->project, 'Kabinet')->id);

    // Another timeline without that item → its only item with a wage.
    $edit(['milestone_id' => $this->panelTimeline->id])->assertSessionHasNoErrors();
    expect($task->fresh()->project_work_item_id)->toBe(targetItem($this->project, 'Panel klinik')->id);

    // The timeline drops Panel klinik → the target loses it.
    $this->actingAs($this->pm)->put(route('milestones.update', $this->panelTimeline), [
        'name' => 'Produksi Panel', 'start_date' => '2026-10-01', 'target_date' => '2026-10-31', 'status' => 'PENDING',
        'work_item_ids' => [targetItem($this->project, 'Plafon')->id],
    ])->assertSessionHasNoErrors();
    expect($task->fresh()->project_work_item_id)->toBeNull();
});

test('changing a target\'s item is audited with the item\'s name', function () {
    storeTarget($this, $this->mixedTimeline);
    $task = Task::sole();

    $this->actingAs($this->qa)->put(route('tasks.update', $task), [
        'title' => $task->title, 'due_date' => '2026-10-14', 'priority' => 'MEDIUM', 'assignee_id' => $this->tukang->id,
        'milestone_id' => $this->mixedTimeline->id, 'project_work_item_id' => targetItem($this->project, 'Kabinet')->id,
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('audit_logs', ['action' => 'task.updated', 'model_id' => $task->id]);
    $log = AuditLog::where('action', 'task.updated')->latest('id')->first();
    expect($log->new_values['item_rab'])->toBe('Kabinet')
        ->and($log->old_values['item_rab'])->toBeNull();
});

test('a Tukang never changes the item of a target — the edit route refuses, the status update ignores it', function () {
    storeTarget($this, $this->mixedTimeline, ['project_work_item_id' => targetItem($this->project, 'Kabinet')->id]);
    $task = Task::sole();

    $this->actingAs($this->tukang)->put(route('tasks.update', $task), [
        'title' => $task->title, 'due_date' => '2026-10-14', 'priority' => 'MEDIUM', 'assignee_id' => $this->tukang->id,
        'milestone_id' => $this->mixedTimeline->id, 'project_work_item_id' => targetItem($this->project, 'Panel gigi')->id,
    ])->assertForbidden();

    $this->actingAs($this->tukang)->patch(route('tasks.updateStatus', $task), [
        'status' => 'ONPROGRESS', 'project_work_item_id' => targetItem($this->project, 'Panel gigi')->id,
    ])->assertSessionHasNoErrors();

    expect($task->fresh()->project_work_item_id)->toBe(targetItem($this->project, 'Kabinet')->id);
});

test('targets are set by the project\'s Pengawas, the Admin Proyek or the PM — never another QA or Logistik', function () {
    $item = ['project_work_item_id' => targetItem($this->project, 'Kabinet')->id];

    storeTarget($this, $this->mixedTimeline, $item, $this->admin)->assertSessionHasNoErrors();
    storeTarget($this, $this->mixedTimeline, $item, $this->pm)->assertSessionHasNoErrors();
    storeTarget($this, $this->mixedTimeline, $item, $this->otherQa)->assertForbidden();
    storeTarget($this, $this->mixedTimeline, $item, $this->logistics)->assertForbidden();

    expect(Task::count())->toBe(2);
});

test('Progres Target = done ÷ all targets of the item (OVER counted, repairs not), per finishing day, project weighted by bobot', function () {
    // Kabinet (bobot 50): 3 of 4 done — one OVER still owed.
    // (completed_at is stored in the app timezone, WIB — the day the dot sits on.)
    plantTarget($this, 'Kabinet', TaskStatus::Done, '2026-10-12 16:00:00');
    plantTarget($this, 'Kabinet', TaskStatus::Done, '2026-10-14 08:00:00');
    plantTarget($this, 'Kabinet', TaskStatus::Done, '2026-10-14 09:30:00');
    plantTarget($this, 'Kabinet', TaskStatus::Over);
    // Panel gigi (bobot 25): 1 of 4.
    plantTarget($this, 'Panel gigi', TaskStatus::Done, '2026-10-14 02:00:00');
    foreach (range(1, 3) as $_) {
        plantTarget($this, 'Panel gigi', TaskStatus::Pending);
    }
    // A repair task on Panel gigi (from a finding) never counts.
    $repair = plantTarget($this, 'Panel gigi', TaskStatus::Pending);
    SiteFinding::create(['project_id' => $this->project->id, 'milestone_id' => $this->mixedTimeline->id, 'task_id' => $repair->id, 'description' => 'Sambungan renggang', 'status' => 'TERBUKA', 'reported_by' => $this->qa->id]);
    // Panel klinik: no target → left out of the project figure.

    $target = app(WorkItemService::class)->targetProgress($this->project);
    $kabinet = $target['items'][targetItem($this->project, 'Kabinet')->id];

    expect($kabinet['planned'])->toBe(4)
        ->and($kabinet['done'])->toBe(3)
        ->and($kabinet['percent'])->toBe(75.0)
        ->and($kabinet['days'])->toBe(['2026-10-12' => 1, '2026-10-14' => 2])
        ->and($target['items'][targetItem($this->project, 'Panel gigi')->id]['percent'])->toBe(25.0)
        ->and($target['items'])->not->toHaveKey(targetItem($this->project, 'Panel klinik')->id)
        // (50 × 75 + 25 × 25) ÷ (50 + 25) = 58,33
        ->and($target['total'])->toBe(58.33);
});

test('targets move neither the money nor the project progress', function () {
    $service = app(WorkItemService::class);
    $before = [$service->payableNow($this->project), $this->project->fresh()->actualProgress(), $service->weightedProgress($this->project)];

    plantTarget($this, 'Kabinet', TaskStatus::Done, '2026-10-14 02:00:00');
    plantTarget($this, 'Panel gigi', TaskStatus::Done, '2026-10-14 02:00:00');

    expect([$service->payableNow($this->project), $this->project->fresh()->actualProgress(), $service->weightedProgress($this->project)])->toBe($before)
        ->and($service->targetProgress($this->project)['total'])->toBe(100.0);
});

test('the Lembar Progres and the pengecekan carry Progres Target and the gap; Logistik gets neither, nor the item list', function () {
    config(['daiku.execution.target_gap_warning' => 25]);
    plantTarget($this, 'Kabinet', TaskStatus::Done, '2026-10-14 02:00:00');
    plantTarget($this, 'Kabinet', TaskStatus::Pending);
    $kabinet = targetItem($this->project, 'Kabinet')->id;

    $this->actingAs($this->qa)->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page
            ->where('execution.progressRecap.target_total', 50)
            ->where('execution.progressRecap.target_gap', 25)
            ->where('execution.progressRecap.items', fn ($items) => collect($items)->firstWhere('id', $kabinet)['target']['percent'] == 50
                && collect($items)->firstWhere('id', $kabinet)['target']['days'] == ['2026-10-14' => 1])
            ->where('execution.workCheck.items', fn ($items) => collect($items)->firstWhere('id', $kabinet)['target']['done'] === 1
                && ! array_key_exists('days', collect($items)->firstWhere('id', $kabinet)['target']))
            ->where('execution.workCheck.summary.target_gap', 25)
            // The target form's "Item RAB" (the Pengawas plans targets, not timelines).
            ->where('timelineItems', fn ($items) => collect($items)->pluck('description')->contains('Kabinet'))
            ->where('tasks', fn ($tasks) => collect($tasks)->pluck('work_item.quotation_item.description')->filter()->unique()->values()->all() === ['Kabinet']));

    $this->actingAs($this->logistics)->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page
            ->where('execution.progressRecap', null)
            ->where('execution.workCheck', null)
            ->where('timelineItems', []));
});

test('the Tukang\'s Hari Ini card names the item of the target', function () {
    storeTarget($this, $this->panelTimeline)->assertSessionHasNoErrors();

    $this->actingAs($this->tukang)->get(route('today.index'))
        ->assertInertia(fn (Assert $page) => $page->where('tasks.0.work_item.quotation_item.description', 'Panel klinik'));
});
