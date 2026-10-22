<?php

use App\Enums\ProjectPhase;
use App\Enums\TaskStatus;
use App\Models\Milestone;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectWorkItem;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Task;
use App\Models\User;
use App\Services\ActionInboxService;
use App\Services\AnalyticsService;
use App\Services\ProductionService;
use App\Services\WeeklyReportService;
use App\Services\WorkItemService;
use App\Support\NotificationTarget;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 29 Sub 10 (B7) — "Progres proyek berbobot": a project with RAB
 * items that carry a wage takes its progress from the Pengawas's
 * pengecekan, Σ (bobot_i × progres_i) ÷ 100 (File C); a project without
 * (older ones) keeps actual activity. Lists, dashboards, the 70 %
 * "Berangkat ke Lokasi" and its notification all read the same number.
 */

beforeEach(function () {
    // Wednesday, a working day.
    Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', 'Asia/Jakarta'));
    $this->seed(RoleSeeder::class);
    $this->ceo = weightedUser('CEO');
    $this->pm = weightedUser('PM');
    $this->qa = weightedUser('QA');
    $this->tukang = weightedUser('FIELD_STAFF');
});

afterEach(fn () => Carbon::setTestNow());

function weightedUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * A producing project of $test->pm (Pengawas $test->qa) whose RAB Proyek has
 * `$items` = [uraian => nilai (juta)], every one with a Satuan Upah, plus an
 * optional item without a wage (never weighed).
 *
 * @param  array<string, float>  $items
 */
function weightedProject(object $test, array $items, bool $withUnpaidItem = true): Project
{
    $quotation = Quotation::factory()->create(['type' => 'PROYEK']);
    $sort = 0;

    foreach ($items as $description => $nilai) {
        QuotationItem::factory()->create([
            'quotation_id' => $quotation->id, 'description' => $description, 'qty' => 1,
            'unit_price' => $nilai * 1_000_000, 'total_price' => $nilai * 1_000_000, 'sort_order' => $sort++,
        ]);
    }

    if ($withUnpaidItem) {
        // Barang jadi — no wage, so no bobot (File A/C).
        QuotationItem::factory()->create([
            'quotation_id' => $quotation->id, 'description' => 'Barang jadi', 'qty' => 1,
            'unit_price' => 50_000_000, 'total_price' => 50_000_000, 'sort_order' => $sort,
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

function weightedItem(Project $project, string $description): ProjectWorkItem
{
    return $project->workItems()->whereHas('quotationItem', fn ($q) => $q->where('description', $description))->sole();
}

/** @param  array<string, int|float>  $percents  uraian => % added */
function weightedCheck(Project $project, array $percents): array
{
    return collect($percents)
        ->map(fn ($percent, string $description) => ['item_id' => weightedItem($project, $description)->id, 'percent' => $percent])
        ->values()
        ->all();
}

/** A project without items with a wage: `$done` of `$total` daily targets DONE. */
function activityProject(object $test, int $done, int $total): Project
{
    $project = Project::factory()->create(['pm_id' => $test->pm->id, 'phase' => ProjectPhase::Produksi->value]);
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'start_date' => '2026-10-12', 'target_date' => '2026-10-24']);

    foreach (range(1, $total) as $i) {
        Task::factory()->create([
            'project_id' => $project->id, 'milestone_id' => $milestone->id, 'assignee_id' => $test->tukang->id,
            'due_date' => '2026-10-14', 'planned_date' => '2026-10-14',
            'status' => ($i <= $done ? TaskStatus::Done : TaskStatus::Pending)->value,
        ]);
    }

    return $project;
}

/**
 * File C — `SIMULASI PERSENTASI.xlsx`: 20 panels, nilai in juta (bobot =
 * nilai ÷ Σ nilai × 100, Σ = 313,8), the % added at the pengecekan of
 * 9 · 14 · 20 · 25 Okt.
 *
 * @return array<string, array{0: float, 1: list<int>}>
 */
function fileC(): array
{
    return [
        'Panel klinik 1' => [15, [0, 30, 50, 0]],
        'Panel klinik 2' => [18.7, [0, 30, 50, 0]],
        'Panel klinik 3' => [18.7, [0, 30, 50, 0]],
        'Panel klinik 4' => [13, [0, 0, 70, 0]],
        'Panel klinik 5' => [11, [80, 0, 20, 0]],
        'Jantung kiri' => [10.8, [0, 0, 50, 0]],
        'Jantung kanan' => [18.1, [0, 0, 50, 0]],
        'BD 1' => [11.2, [0, 30, 30, 0]],
        'BD 2' => [9.5, [0, 30, 30, 0]],
        'BD 3' => [14.2, [0, 0, 30, 0]],
        'Klinik 1 uk. 2970' => [13.9, [0, 30, 30, 0]],
        'Klinik EEG' => [19.7, [80, 0, 20, 0]],
        'Gigi 1' => [18.95, [0, 0, 0, 50]],
        'Gigi 2' => [18.95, [0, 30, 0, 50]],
        'Gigi 3' => [19.2, [0, 30, 0, 50]],
        'Orthopedi 1' => [13.2, [0, 0, 0, 50]],
        'Orthopedi 2' => [15.2, [0, 30, 0, 50]],
        'THT kanan' => [17.5, [0, 30, 0, 50]],
        'THT kiri' => [18.5, [0, 0, 0, 50]],
        'Ruang tindakan' => [18.5, [0, 0, 0, 50]],
    ];
}

test('File C: Σ (bobot × progres) ÷ 100 = 67,70 % — the project progress, its basis "weighted"', function () {
    $project = weightedProject($this, array_map(fn (array $row) => $row[0], fileC()));
    $service = app(WorkItemService::class);

    // Items with a wage, no pengecekan yet: 0 %, weighted (not the daily targets).
    expect($project->actualProgress())->toBe(0.0)
        ->and($project->progressFigure()['basis'])->toBe(Project::PROGRESS_WEIGHTED);

    foreach ([0, 1, 2, 3] as $check) {
        $service->recordProgress($project, weightedCheck($project, array_filter(array_map(fn (array $row) => $row[1][$check], fileC()))), null, $this->qa);

        if ($check === 0) {
            // 9 Okt: (11 × 80 + 19,7 × 80) ÷ 313,8.
            expect($project->actualProgress())->toBe(7.83);
        }
    }

    // Item weights from the RAB value of the items with a wage only (Barang jadi left out).
    $bobot = collect($service->checkOverview($project, true)['items'])->keyBy('description');

    expect($service->weightedProgress($project))->toBe(67.7)
        ->and($service->figures($project)['weighted_progress'])->toBe(67.7)
        ->and($project->actualProgress())->toBe(67.7)
        ->and($project->progressFigure())->toBe(['progress' => 67.7, 'basis' => 'weighted'])
        ->and($bobot['Panel klinik 1']['weight'])->toBe(4.78)
        ->and($bobot)->not->toHaveKey('Barang jadi')
        // The list path (one aggregate) gives the very same number.
        ->and(Project::query()->whereKey($project->id)->withActivityCounts()->sole()->listedProgress())->toBe(['progress' => 67.7, 'basis' => 'weighted'])
        // Actual activity stays what it is — the Activity Target tab's figure.
        ->and($project->activityProgress())->toBe(0)
        ->and(app(ProductionService::class)->activityTarget($project)['summary']['progress'])->toBe(0);
});

test('a project without items with a wage keeps actual activity (also when its work items have no wage yet)', function () {
    $older = activityProject($this, 3, 4);

    expect($older->actualProgress())->toBe(75)
        ->and($older->progressFigure()['basis'])->toBe(Project::PROGRESS_ACTIVITY)
        ->and(Project::query()->whereKey($older->id)->withActivityCounts()->sole()->listedProgress())->toBe(['progress' => 75, 'basis' => 'activity']);

    // Work items made (daiku:work-items) but no Satuan Upah yet: still actual activity.
    $quotation = Quotation::factory()->create(['type' => 'PROYEK']);
    QuotationItem::factory()->create(['quotation_id' => $quotation->id, 'qty' => 1, 'unit_price' => 1_000_000, 'total_price' => 1_000_000]);
    $older->update(['quotation_id' => $quotation->id]);
    app(WorkItemService::class)->ensureForProject($older);

    expect($older->workItems()->count())->toBe(1)
        ->and($older->fresh()->actualProgress())->toBe(75)
        ->and($older->fresh()->progressFigure()['basis'])->toBe('activity');

    // A list row without any daily target shows "—" (null), as before.
    $empty = Project::factory()->create();
    expect(Project::query()->whereKey($empty->id)->withActivityCounts()->sole()->listedProgress())->toBe(['progress' => null, 'basis' => 'activity']);
});

test('lists & dashboards read the weighted value — in the same number of queries for 1 or 5 projects', function () {
    $weighted = weightedProject($this, ['Kabinet' => 30, 'Meja' => 10]);
    app(WorkItemService::class)->recordProgress($weighted, weightedCheck($weighted, ['Kabinet' => 80, 'Meja' => 40]), null, $this->qa);
    $older = activityProject($this, 1, 4);

    // (30 × 80 + 10 × 40) ÷ 40 = 70.
    $this->actingAs($this->ceo)->get(route('projects.index'))->assertInertia(fn (Assert $page) => $page
        ->where('projects.data', fn ($rows) => collect($rows)->firstWhere('id', $weighted->id)['actual_progress'] === 70
            && collect($rows)->firstWhere('id', $weighted->id)['progress_basis'] === 'weighted'
            && collect($rows)->firstWhere('id', $older->id)['actual_progress'] === 25
            && collect($rows)->firstWhere('id', $older->id)['progress_basis'] === 'activity'
            && ! array_key_exists('work_rab_total', collect($rows)->firstWhere('id', $weighted->id))));

    $this->actingAs($this->pm)->get(route('projects.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('projects', fn ($rows) => collect($rows)->firstWhere('id', $weighted->id)['progress'] === 70
            && collect($rows)->firstWhere('id', $weighted->id)['progressBasis'] === 'weighted'
            && collect($rows)->firstWhere('id', $older->id)['progress'] === 25));

    $analytics = app(AnalyticsService::class)->activeProjects()->keyBy('id');
    expect($analytics[$weighted->id]['progress'])->toBe(70.0)
        ->and($analytics[$weighted->id]['progressBasis'])->toBe('weighted')
        ->and($analytics[$older->id]['progress'])->toBe(25);

    // No query per row: the list costs the same with four more weighted projects.
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->ceo)->get(route('projects.index'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $before = $count();
    foreach (range(1, 4) as $i) {
        $more = weightedProject($this, ["Item {$i}" => 10]);
        app(WorkItemService::class)->recordProgress($more, weightedCheck($more, ["Item {$i}" => 50]), null, $this->qa);
    }

    expect($count())->toBe($before);
});

test('70 % from a pengecekan unlocks "Berangkat ke Lokasi" and tells the PM once', function () {
    $project = weightedProject($this, ['Kabinet' => 30, 'Meja' => 10]);
    $notified = fn () => Notification::where('user_id', $this->pm->id)->where('type', 'project_progress_reached')->count();

    // Week 1: 60 % → locked, the reason names the progres berbobot.
    $this->actingAs($this->qa)->post(route('projects.weekly-reports.store', $project), ['notes' => 'Rangka kabinet & meja', 'progress' => weightedCheck($project, ['Kabinet' => 60, 'Meja' => 60])])
        ->assertSessionHasNoErrors();
    expect($project->fresh()->actualProgress())->toBe(60.0)
        ->and($notified())->toBe(0);
    $this->actingAs($this->pm)->post(route('projects.departure.approve', $project))
        ->assertSessionHasErrors(['departure' => 'Proyek baru progres berbobot 60% — berangkat ke lokasi terbuka mulai 70%.']);

    // Daily targets all done (actual activity 100 %) — still the pengecekan that counts.
    $done = activityProject($this, 1, 1)->tasks()->first();
    $done->update(['project_id' => $project->id]);
    expect($project->fresh()->activityProgress())->toBe(100)
        ->and($project->fresh()->actualProgress())->toBe(60.0);

    // Week 2: +15 % → 75 %: the PM is told by the pengecekan itself.
    Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00', 'Asia/Jakarta'));
    $this->actingAs($this->qa)->post(route('projects.weekly-reports.store', $project), ['notes' => 'Finishing kabinet', 'progress' => weightedCheck($project, ['Kabinet' => 15, 'Meja' => 15])])
        ->assertSessionHasNoErrors();
    expect($project->fresh()->actualProgress())->toBe(75.0)
        ->and($notified())->toBe(1)
        ->and(Notification::where('user_id', $this->pm->id)->where('type', 'project_progress_reached')->sole()->message)
        ->toContain('progres berbobot 75%');

    // The Eksekusi tab, the Ringkasan and the stepper read the same figure and name its basis.
    $this->actingAs($this->pm)->get(route('projects.show', ['project' => $project, 'tab' => 'execution']))->assertInertia(fn (Assert $page) => $page
        ->where('project.actual_progress', 75)
        ->where('project.progress_basis', 'weighted')
        ->where('execution.progress', 75)
        ->where('execution.progressBasis', 'weighted')
        ->where('execution.threshold', 70));

    // Week 3: another pengecekan before leaving — no second notification.
    Carbon::setTestNow(Carbon::parse('2026-10-28 10:00:00', 'Asia/Jakarta'));
    $this->actingAs($this->qa)->post(route('projects.weekly-reports.store', $project), ['notes' => 'Siap kirim', 'progress' => weightedCheck($project, ['Kabinet' => 5])])
        ->assertSessionHasNoErrors();
    expect($notified())->toBe(1);

    $this->actingAs($this->pm)->post(route('projects.departure.approve', $project))->assertSessionHasNoErrors();
    expect($project->fresh()->phase)->toBe(ProjectPhase::Pemasangan);
});

test('H-30 %: the reminder names the progres berbobot of a weighted project', function () {
    $project = weightedProject($this, ['Kabinet' => 30, 'Meja' => 10]);
    app(WorkItemService::class)->recordProgress($project, weightedCheck($project, ['Kabinet' => 50, 'Meja' => 50]), null, $this->qa);

    expect(app(ProductionService::class)->sendDeadlineReminders(Carbon::parse('2026-10-28', 'Asia/Jakarta')))->toBe(1)
        ->and(Notification::where('user_id', $this->pm->id)->where('type', 'project_deadline_near')->sole()->message)->toContain('progres berbobot 50%');
});

// ── Sprint 31: File C as a table, several pengecekan a week ─────────────

test('Rekap Progres per Item: the File C table — % per pengecekan date, Total % (AU), Kontribusi (AV), 67,70 % (AV24)', function () {
    $project = weightedProject($this, array_map(fn (array $row) => $row[0], fileC()));
    $service = app(WorkItemService::class);

    foreach (['2026-10-09', '2026-10-14', '2026-10-20', '2026-10-25'] as $check => $date) {
        Carbon::setTestNow(Carbon::parse("{$date} 10:00:00", 'Asia/Jakarta'));
        $service->recordProgress($project, weightedCheck($project, array_filter(array_map(fn (array $row) => $row[1][$check], fileC()))), null, $this->qa);
    }

    $recap = $service->progressRecap($project);
    $rows = collect($recap['items'])->keyBy('description');

    expect($recap['dates'])->toBe(['2026-10-09', '2026-10-14', '2026-10-20', '2026-10-25'])
        // Row 4 of the sheet: 30 % on 14 Okt + 50 % on 20 Okt = 80 %, × bobot 4,7801 = 3,82.
        ->and($rows['Panel klinik 1']['weight'])->toBe(4.78)
        ->and((array) $rows['Panel klinik 1']['cells'])->toBe(['2026-10-14' => 30, '2026-10-20' => 50])
        ->and($rows['Panel klinik 1']['total'])->toEqual(80)
        ->and($rows['Panel klinik 1']['contribution'])->toBe(3.82)
        // Row 15 (Klinik EEG): 80 + 20 = 100 % → its whole bobot 6,28.
        ->and($rows['Klinik EEG']['contribution'])->toBe(6.28)
        ->and($rows)->not->toHaveKey('Barang jadi')
        // Per date: 9 Okt adds 7,83 %; the running total ends on AV24.
        ->and($recap['per_date'][0])->toBe(['date' => '2026-10-09', 'added' => 7.83, 'cumulative' => 7.83])
        ->and(end($recap['per_date'])['cumulative'])->toBe(67.7)
        ->and($recap['total'])->toBe(67.7)
        ->and($recap['total'])->toBe($service->weightedProgress($project));

    // Shown on the Eksekusi tab to the pengecekan viewers, never to Logistik.
    $this->actingAs($this->pm)->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page->where('execution.progressRecap.total', 67.7));
    $logistics = weightedUser('LOGISTICS');
    $this->actingAs($logistics)->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page->where('execution.progressRecap', null));
});

test('several pengecekan a week (File C: Monday 20 and Saturday 25 Okt) — within the week one waits for the PM at a time', function () {
    $project = weightedProject($this, ['Kabinet' => 10, 'Meja' => 10]);
    $project->update(['production_started_at' => '2026-10-01 08:00:00']);
    $reports = app(WeeklyReportService::class);
    $check = fn (int $percent) => ['notes' => 'Pengecekan di lokasi.', 'progress' => weightedCheck($project, ['Kabinet' => $percent, 'Meja' => $percent])];

    Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', 'Asia/Jakarta'));
    $first = $reports->submit($project, $check(30), $this->qa);
    expect($first->sequence)->toBe(1)
        ->and($first->label())->toBe('Minggu ke-4')
        ->and($reports->canSubmit($project))->toBeFalse();

    // A second one while the first waits for the PM is refused.
    $this->actingAs($this->qa)->post(route('projects.weekly-reports.store', $project), $check(10))->assertSessionHasErrors('notes');

    $reports->decide($first, true, null, null, $this->pm);
    expect($reports->canSubmit($project))->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-10-25 10:00:00', 'Asia/Jakarta'));
    $this->actingAs($this->qa)->post(route('projects.weekly-reports.store', $project), $check(20))->assertSessionHasNoErrors();
    $second = $project->weeklyReports()->first();

    expect($second->id)->not->toBe($first->id)
        ->and($second->week_start->toDateString())->toBe($first->week_start->toDateString())
        ->and($second->sequence)->toBe(2)
        ->and($second->label())->toBe('Minggu ke-4 · Pengecekan ke-2')
        ->and($second->payment->label)->toContain('Mingguan minggu ke-4 · Pengecekan ke-2')
        ->and(app(WorkItemService::class)->weightedProgress($project))->toBe(50.0)
        ->and(collect(app(WorkItemService::class)->progressRecap($project)['dates'])->all())->toBe(['2026-10-20', '2026-10-25']);
});

test('a pengecekan sent leads the PM to Item RAB › Lembar Progres (notification, Perlu Tindakan) and back to the Pengawas when decided', function () {
    $project = weightedProject($this, ['Kabinet' => 10, 'Meja' => 10]);
    $project->update(['production_started_at' => '2026-10-01 08:00:00']);
    $reports = app(WeeklyReportService::class);
    $sheet = route('projects.show', ['project' => $project->id, 'tab' => 'items', 'view' => 'progres']);

    $report = $reports->submit($project, ['notes' => 'Pengecekan di lokasi.', 'progress' => weightedCheck($project, ['Kabinet' => 20])], $this->qa);

    $sent = Notification::where('user_id', $this->pm->id)->where('type', 'weekly_report_submitted')->sole();
    $queue = collect(app(ActionInboxService::class)->for($this->pm, fresh: true))->firstWhere('key', 'execution-pm');
    expect($sent->message)->toContain('Item RAB › Lembar Progres')
        ->and(NotificationTarget::for($sent))->toBe($sheet)
        ->and($queue['items'][0]['subtitle'])->toBe('Pengecekan Pengawas menunggu ACC')
        ->and($queue['items'][0]['href'])->toBe($sheet);

    // The PM reads it on the sheet; deciding sends the Pengawas back there.
    $this->actingAs($this->pm)->get($sheet)->assertInertia(fn (Assert $page) => $page
        ->where('execution.can.decide', true)
        ->where('execution.weeklyReports.0.status', 'DIAJUKAN')
        ->where('execution.weeklyReports.0.progress.0.percent', 20));

    $reports->decide($report, true, 0, null, $this->pm);
    $decided = Notification::where('user_id', $this->qa->id)->where('type', 'weekly_report_decided')->sole();
    expect(NotificationTarget::for($decided))->toBe($sheet);
});

test('an older project without wages per item still sends one report a week', function () {
    $project = activityProject($this, 1, 2);
    $project->supervisors()->attach($this->qa->id);
    $project->update(['production_started_at' => '2026-10-01 08:00:00']);
    $reports = app(WeeklyReportService::class);

    $first = $reports->submit($project, ['notes' => 'Laporan minggu ini.'], $this->qa);
    $reports->decide($first, true, null, null, $this->pm);

    expect($reports->canSubmit($project))->toBeFalse()
        ->and(fn () => $reports->submit($project, ['notes' => 'Laporan kedua.'], $this->qa))->toThrow(ValidationException::class);
});
