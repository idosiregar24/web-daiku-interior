<?php

use App\Enums\ProjectPhase;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WorkItemProgressDraft;
use App\Services\WeeklyReportService;
use App\Services\WorkItemService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 36 — the pengecekan (= the wage request) is sent on Friday or
 * Saturday only; Monday–Thursday it is filled as a draft. Once a week; a
 * pengecekan the PM rejected may be sent again (corrected) the same week.
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    foreach (['pm' => 'PM', 'qa' => 'QA', 'admin' => 'ADMIN_PROYEK'] as $key => $role) {
        $this->{$key} = User::factory()->create();
        $this->{$key}->assignRole($role);
    }

    $quotation = Quotation::factory()->create(['type' => 'PROYEK']);
    QuotationItem::factory()->create(['quotation_id' => $quotation->id, 'description' => 'Kabinet', 'qty' => 1, 'unit_price' => 10_000_000, 'total_price' => 10_000_000]);
    $this->project = Project::factory()->create([
        'pm_id' => $this->pm->id, 'quotation_id' => $quotation->id, 'phase' => ProjectPhase::Produksi->value,
        'start_date' => '2026-10-01', 'end_date' => '2026-11-30', 'production_started_at' => '2026-10-05 08:00:00',
    ]);
    $this->project->supervisors()->attach($this->qa->id);
    $service = app(WorkItemService::class);
    $service->ensureForProject($this->project);
    $this->item = $this->project->workItems()->sole();
    $service->update($this->item, ['unit_wage' => 1_000_000], $this->qa);
    $this->check = fn (int $percent) => ['notes' => 'Pengecekan di lokasi.', 'progress' => [['item_id' => $this->item->id, 'percent' => $percent]]];
    $this->at = fn (string $moment) => Carbon::setTestNow(Carbon::parse($moment, 'Asia/Jakarta'));
});

afterEach(fn () => Carbon::setTestNow());

test('Monday–Thursday the pengecekan is a draft only; Friday it is sent', function () {
    // Wednesday 21 Okt: the draft saves, sending is refused.
    ($this->at)('2026-10-21 10:00:00');
    $this->actingAs($this->qa)->put(route('projects.work-check.draft', $this->project), ['progress' => [['item_id' => $this->item->id, 'percent' => 20]]])
        ->assertSessionHasNoErrors();
    expect(WorkItemProgressDraft::where('project_id', $this->project->id)->exists())->toBeTrue();

    $this->actingAs($this->qa)->post(route('projects.weekly-reports.store', $this->project), ($this->check)(20))
        ->assertSessionHasErrors(['notes' => 'Pengecekan dikirim hari Jumat atau Sabtu — sebelum itu isian disimpan sebagai draf.']);
    expect(WeeklyReport::count())->toBe(0);

    $this->actingAs($this->qa)->get(route('projects.show', $this->project))->assertInertia(fn (Assert $page) => $page
        ->where('execution.currentWeek.open', false)
        ->where('execution.currentWeek.window_open', false)
        ->where('execution.currentWeek.send_days', 'Jumat atau Sabtu'));

    // Friday 23 Okt: sent.
    ($this->at)('2026-10-23 10:00:00');
    $this->actingAs($this->qa)->post(route('projects.weekly-reports.store', $this->project), ($this->check)(20))->assertSessionHasNoErrors();
    expect(WeeklyReport::sole()->status->value)->toBe('DIAJUKAN');
});

test('once a week — a second one the same week is refused, also after the PM approved; next week it opens again', function () {
    ($this->at)('2026-10-23 10:00:00');
    $reports = app(WeeklyReportService::class);
    $first = $reports->submit($this->project, ($this->check)(20), $this->qa);

    $this->actingAs($this->qa)->post(route('projects.weekly-reports.store', $this->project), ($this->check)(10))->assertSessionHasErrors('notes');

    $reports->decide($first, true, null, null, $this->pm);
    // Saturday, same week, already approved → still closed.
    ($this->at)('2026-10-24 10:00:00');
    expect($reports->canSubmit($this->project->fresh()))->toBeFalse();
    $this->actingAs($this->qa)->post(route('projects.weekly-reports.store', $this->project), ($this->check)(10))
        ->assertSessionHasErrors(['notes' => 'Pengecekan minggu ke-3 sudah dikirim — berikutnya minggu depan.']);

    // Next Friday.
    ($this->at)('2026-10-30 10:00:00');
    expect($reports->canSubmit($this->project->fresh()))->toBeTrue();
});

test('a Friday missed is sent on Saturday; a rejected pengecekan is sent again the same week', function () {
    ($this->at)('2026-10-24 09:00:00');
    $reports = app(WeeklyReportService::class);
    $first = $reports->submit($this->project, ($this->check)(30), $this->qa);
    $reports->decide($first, false, null, 'Foto belum ada.', $this->pm);

    expect($reports->canSubmit($this->project->fresh()))->toBeTrue();
    $this->actingAs($this->qa)->post(route('projects.weekly-reports.store', $this->project), ['notes' => 'Dikirim ulang dengan foto.', 'progress' => [['item_id' => $this->item->id, 'percent' => -5, 'note' => 'Koreksi']]])
        ->assertSessionHasNoErrors();

    $again = WeeklyReport::where('project_id', $this->project->id)->orderByDesc('id')->first();
    expect($again->sequence)->toBe(2)->and($again->status->value)->toBe('DIAJUKAN');
});

test('the Admin Proyek fills the draft any day but never sends', function () {
    ($this->at)('2026-10-23 10:00:00');
    $this->actingAs($this->admin)->put(route('projects.work-check.draft', $this->project), ['progress' => [['item_id' => $this->item->id, 'percent' => 10]]])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('projects.weekly-reports.store', $this->project), ($this->check)(10))->assertForbidden();
});
