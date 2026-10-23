<?php

use App\Enums\ProjectPhase;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectWorkItem;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectStepsService;
use App\Services\WeeklyReportService;
use App\Services\WorkItemService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 33 — "Langkah Kerja": the running phase's work in the order it is
 * filled (after the TM: upah per item → jadwal per item → daily target →
 * bahan → DP), with who does it and where — at the top of Detail Proyek.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', 'Asia/Jakarta'));
    // Sprint 36 — the Friday/Saturday send window is tested in WeeklyReportWindowTest; here any day sends.
    config(['daiku.execution.weekly_report_send_days' => [1, 2, 3, 4, 5, 6, 7]]);
    $this->seed(RoleSeeder::class);

    foreach (['pm' => 'PM', 'qa' => 'QA', 'marketing' => 'MARKETING'] as $key => $role) {
        $this->{$key} = User::factory()->create();
        $this->{$key}->assignRole($role);
    }

    $quotation = Quotation::factory()->create(['type' => 'PROYEK']);
    foreach (['Kabinet', 'Meja'] as $sort => $description) {
        QuotationItem::factory()->create(['quotation_id' => $quotation->id, 'description' => $description, 'qty' => 1, 'unit_price' => 10_000_000, 'total_price' => 10_000_000, 'sort_order' => $sort]);
    }
    $this->project = Project::factory()->create([
        'pm_id' => $this->pm->id, 'quotation_id' => $quotation->id, 'phase' => ProjectPhase::Persiapan->value,
        'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'team_lead_name' => 'Putra',
    ]);
    $this->project->supervisors()->attach($this->qa->id);
    app(WorkItemService::class)->ensureForProject($this->project);
});

afterEach(fn () => Carbon::setTestNow());

function stepsOf(Project $project, User $user): array
{
    return collect(app(ProjectStepsService::class)->for($project->fresh(), $user)['items'])->keyBy('key')->all();
}

test('Persiapan in the order it is filled: upah → jadwal → daily target → bahan → DP, the first open one is current', function () {
    $steps = stepsOf($this->project, $this->qa);

    expect(array_keys($steps))->toBe(['wages', 'schedule', 'targets', 'materials', 'dp-request', 'dp-approve'])
        ->and($steps['wages']['state'])->toBe('current')
        ->and($steps['wages']['mine'])->toBeTrue()
        ->and($steps['wages']['target'])->toBe(['tab' => 'items', 'view' => 'upah'])
        ->and($steps['schedule']['state'])->toBe('todo')
        ->and($steps['dp-approve']['mine'])->toBeFalse();

    // Wages filled → the item schedule is next.
    $service = app(WorkItemService::class);
    $this->project->workItems()->get()->each(fn (ProjectWorkItem $item) => $service->update($item, ['unit_wage' => 100_000], $this->qa));
    $steps = stepsOf($this->project, $this->qa);
    expect($steps['wages']['state'])->toBe('done')
        ->and($steps['wages']['detail'])->toBe('2 dari 2 item berupah')
        ->and($steps['schedule']['state'])->toBe('current')
        ->and($steps['schedule']['detail'])->toBe('2 item belum masuk timeline')
        // Sprint 35 — the schedule is the Timeline: the PM's step there, not the Pengawas's.
        ->and($steps['schedule']['target'])->toBe(['tab' => 'milestone'])
        ->and($steps['schedule']['mine'])->toBeFalse()
        ->and(stepsOf($this->project, $this->pm)['schedule']['mine'])->toBeTrue();

    // The PM reads the same list; its own step is the DP decision.
    expect(stepsOf($this->project, $this->pm)['wages']['mine'])->toBeFalse()
        ->and(stepsOf($this->project, $this->pm)['dp-approve']['mine'])->toBeTrue();
});

test('Produksi: recurring work is ongoing until something waits — a sent pengecekan is current for the PM', function () {
    $service = app(WorkItemService::class);
    $this->project->workItems()->get()->each(fn (ProjectWorkItem $item) => $service->update($item, ['unit_wage' => 100_000], $this->qa));
    $this->project->update(['phase' => ProjectPhase::Produksi->value, 'production_started_at' => '2026-10-05 08:00:00']);
    $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
    Task::factory()->create(['project_id' => $this->project->id, 'milestone_id' => $milestone->id, 'status' => 'PENGECEKAN']);

    $steps = stepsOf($this->project, $this->qa);
    expect(array_keys($steps))->toBe(['daily', 'check', 'check-decide', 'departure'])
        ->and($steps['daily']['state'])->toBe('current')
        ->and($steps['daily']['detail'])->toBe('1 daily target menunggu dicek')
        ->and($steps['check-decide']['state'])->toBe('ongoing')
        ->and($steps['departure']['state'])->toBe('todo');

    $item = $this->project->workItems()->first();
    app(WeeklyReportService::class)->submit($this->project->fresh(), ['notes' => 'Pengecekan di lokasi.', 'progress' => [['item_id' => $item->id, 'percent' => 20]]], $this->qa);

    $steps = stepsOf($this->project, $this->pm);
    expect($steps['check-decide']['state'])->toBe('current')
        ->and($steps['check-decide']['mine'])->toBeTrue()
        ->and($steps['check-decide']['target'])->toBe(['tab' => 'items', 'view' => 'progres'])
        ->and($steps['check']['detail'])->toBe('Terkirim — menunggu ACC PM');

    // A recurring step stays `routine` while current, and "n/m selesai" counts the one-time steps only.
    $all = app(ProjectStepsService::class)->for($this->project->fresh(), $this->pm);
    expect($steps['check-decide']['routine'])->toBeTrue()
        ->and($steps['departure']['routine'])->toBeFalse()
        ->and($all['done'])->toBe(0)
        ->and($all['total'])->toBe(1);
});

test('the steps reach whoever opens the Eksekusi tab; nobody else, and not a closed project', function () {
    $this->actingAs($this->qa)->get(route('projects.show', $this->project))->assertInertia(fn (Assert $page) => $page
        ->where('execution.steps.phase', 'PERSIAPAN')
        ->where('execution.steps.items.0.key', 'wages')
        ->where('execution.steps.next.phase', 'PRODUKSI'));

    $this->project->update(['status' => 'COMPLETED']);
    expect(app(ProjectStepsService::class)->for($this->project->fresh(), $this->qa))->toBeNull();
});

test('TM: "TM Selesai" never waits for the Pengawas — upah & bahan stand beside it, not before it', function () {
    $this->project->update(['phase' => ProjectPhase::Tm->value]);
    $meeting = $this->project->technicalMeeting()->create(['meeting_date' => '2026-10-14', 'notes' => 'Urutan kerja disepakati.']);
    Milestone::factory()->create(['project_id' => $this->project->id]);

    $steps = stepsOf($this->project, $this->pm);

    // No wage and no material yet — the PM's closing step is still the one to do now.
    expect(array_keys($steps))->toBe(['tm-schedule', 'tm-timeline', 'tm-notes', 'tm-complete', 'tm-wages', 'tm-materials'])
        ->and($steps['tm-notes']['state'])->toBe('current')
        ->and($steps['tm-complete']['action'])->toBe('completeMeeting')
        ->and($steps['tm-wages']['state'])->toBe('optional')
        ->and($steps['tm-materials']['state'])->toBe('optional');

    // Notulen & peserta saved → that step is done, "TM Selesai" is the one to press now.
    $meeting->attendees()->create(['name' => 'Pak Darto']);
    $steps = stepsOf($this->project, $this->pm);
    expect($steps['tm-notes']['state'])->toBe('done')
        ->and($steps['tm-complete']['state'])->toBe('current')
        ->and($steps['tm-complete']['detail'])->toBe('Semua syarat lengkap — tekan tombol di bawah.');
});

test('Upah Tukang: one "Simpan" saves every changed row; a locked row refuses the whole save and names that row', function () {
    [$kabinet, $meja] = $this->project->workItems()->orderBy('id')->get()->all();

    $this->actingAs($this->qa)->put(route('projects.work-items.wages', $this->project), ['items' => [
        ['id' => $kabinet->id, 'unit_wage' => 300_000],
        ['id' => $meja->id, 'unit_wage' => 150_000],
    ]])->assertSessionHasNoErrors()->assertSessionHas('success', '2 item tersimpan.');

    expect((float) $kabinet->fresh()->unit_wage)->toBe(300_000.0)->and((float) $meja->fresh()->unit_wage)->toBe(150_000.0);

    // After Start Produksi a wage that was set is locked — nothing of the save is kept.
    $this->project->update(['phase' => ProjectPhase::Produksi->value]);
    $this->actingAs($this->qa)->put(route('projects.work-items.wages', $this->project), ['items' => [
        ['id' => $meja->id, 'unit_wage' => 100_000],
        ['id' => $kabinet->id, 'unit_wage' => 999_000],
    ]])->assertSessionHasErrors('items.0.unit_wage');
    expect((float) $meja->fresh()->unit_wage)->toBe(150_000.0);

    // An item of another project is refused.
    $quotation = Quotation::factory()->create(['type' => 'PROYEK']);
    QuotationItem::factory()->create(['quotation_id' => $quotation->id, 'qty' => 1, 'unit_price' => 1_000_000, 'total_price' => 1_000_000]);
    $otherProject = Project::factory()->create(['quotation_id' => $quotation->id]);
    app(WorkItemService::class)->ensureForProject($otherProject);
    $other = $otherProject->workItems()->sole();
    $this->actingAs($this->qa)->put(route('projects.work-items.wages', $this->project), ['items' => [['id' => $other->id, 'unit_wage' => 1]]])
        ->assertSessionHasErrors('items.0.unit_wage');
});
