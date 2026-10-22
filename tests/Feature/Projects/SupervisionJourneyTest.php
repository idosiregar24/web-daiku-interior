<?php

use App\Enums\LeadStatus;
use App\Enums\ProjectPhase;
use App\Enums\ProjectStatus;
use App\Enums\QuotationStatus;
use App\Enums\SupervisorPaymentKind;
use App\Enums\SupervisorPaymentStatus;
use App\Enums\TaskStatus;
use App\Models\BankAccount;
use App\Models\Lead;
use App\Models\Material;
use App\Models\Milestone;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectOpening;
use App\Models\ProjectWorkItem;
use App\Models\Quotation;
use App\Models\QuotationApproval;
use App\Models\SupervisorPayment;
use App\Models\Task;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Services\ActionInboxService;
use App\Services\QuotationService;
use App\Services\WorkItemService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

/*
 * Sprint 29 Sub 15 — the execution flow of the Sprint 29 revision, end to
 * end through the routes (plan §3): Buka Proyek with two Pengawas → Gambar
 * Kerja → TM Selesai (PM) → Upah Tukang per item RAB (File A) → DP 10 %
 * approved by the PM = Start Produksi → the Tukang's "Selesai", checked by
 * a Pengawas → pengecekan % per item (File B) → pencairan → 70 % →
 * Berangkat ke Lokasi → 100 % → pelunasan → QA per item RAB → COMPLETED.
 * The numbers are the Excel files' (sprint-29/*.xlsx).
 */

beforeEach(function () {
    // Monday.
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00', 'Asia/Jakarta'));
    $this->seed(RoleSeeder::class);

    foreach (['ceo' => 'CEO', 'pm' => 'PM', 'marketing' => 'MARKETING', 'finance' => 'FINANCE', 'qa' => 'QA', 'qa2' => 'QA', 'otherQa' => 'QA',
        'admin' => 'ADMIN_PROYEK', 'logistics' => 'LOGISTICS', 'tukang' => 'FIELD_STAFF', 'architect' => 'DESIGNER'] as $key => $role) {
        $this->{$key} = User::factory()->create(['name' => ucfirst($key)]);
        $this->{$key}->assignRole($role);
    }
    $this->head = User::factory()->create(['name' => 'Kepala Desain']);
    $this->head->assignRole(['DESIGNER', 'KEPALA_DESAIN']);
    $this->bank = BankAccount::factory()->create(['is_active' => true]);
});

afterEach(fn () => Carbon::setTestNow());

function journeyClock(string $date, string $time = '10:00:00'): void
{
    Carbon::setTestNow(Carbon::parse("{$date} {$time}", 'Asia/Jakarta'));
}

/** File A "Dapur Bersih" RAB Proyek, approved by the PM and by the client on the link. */
function fileARab(object $test): Quotation
{
    $lead = Lead::factory()->create(['status' => LeadStatus::DealDesain->value, 'assigned_to' => $test->marketing->id, 'client_name' => 'Bang Binsar']);
    $quotation = Quotation::factory()->create(['lead_id' => $lead->id, 'type' => 'PROYEK', 'status' => QuotationStatus::Draft->value]);
    $service = app(QuotationService::class);
    $service->saveRab($quotation, ['items' => collect([
        ['Kabinet bawah', 3.16, 3_300_000],
        ['Kabinet atas kanan', 2.45, 2_850_000],
        ['Kabinet atas kiri', 1.75, 2_850_000],
        ['Cover kulkas', 4.25, 2_850_000],
        ['Cover atas HPL', 4.4, 1_800_000],
        ['Meja bar', 1.3, 3_800_000],
        ['Rak piring, LED, power supply, saklar', 1, 2_000_000],
    ])->map(fn (array $row) => ['description' => $row[0], 'qty' => $row[1], 'unit_id' => unitId('m1'), 'unit_price' => $row[2]])->all()]);
    $service->savePaymentTerms($quotation, [
        ['label' => 'DP', 'percentage' => 30, 'trigger' => 'DI_MUKA'],
        ['label' => 'Pelunasan', 'percentage' => 70, 'trigger' => 'PROYEK_SELESAI'],
    ]);
    $quotation->refresh();
    QuotationApproval::create(['quotation_id' => $quotation->id, 'version' => $quotation->version, 'approver_id' => $test->pm->id, 'approver_role' => 'PM', 'status' => 'APPROVED']);
    $quotation->update(['status' => QuotationStatus::SentToClient->value, 'valid_until' => '2026-10-19']);
    $service->clientApprove(shareLinkFor($quotation, $test->marketing), true, '10.0.0.1', 'Test');

    return $quotation->fresh();
}

function journeyItem(Project $project, string $description): ProjectWorkItem
{
    return $project->workItems()->whereHas('quotationItem', fn ($q) => $q->where('description', $description))->sole();
}

/** `$percents` = uraian => % added this pengecekan. */
function journeyCheck(Project $project, array $percents): array
{
    return collect($percents)->map(fn ($percent, $description) => ['item_id' => journeyItem($project, $description)->id, 'percent' => $percent])->values()->all();
}

test('Sprint 29 alur eksekusi: dua Pengawas, upah per item, DP = Start Produksi, pengecekan %, pencairan, 70 %, QA per item, COMPLETED', function () {
    // ── Buka Proyek — the approving PM, two Pengawas (Sub 06) ──────────────
    fileARab($this);
    $this->actingAs($this->pm)->post(route('projects.openings.open', ProjectOpening::sole()), [
        'name' => 'Dapur Bersih Bang Binsar', 'pm_id' => $this->pm->id, 'team_lead_name' => 'Putra',
        'supervisor_ids' => [$this->qa->id, $this->qa2->id],
        'opened_on' => '2026-10-05', 'start_date' => '2026-10-05', 'end_date' => '2026-11-30',
    ])->assertSessionHasNoErrors();
    $project = Project::sole();
    expect($project->supervisors()->pluck('users.id')->sort()->values()->all())->toBe(collect([$this->qa->id, $this->qa2->id])->sort()->values()->all())
        ->and($project->phase)->toBe(ProjectPhase::GambarKerja)
        ->and($project->workItems()->count())->toBe(7);

    // ── Gambar Kerja → TM ─────────────────────────────────────────────────
    $drawing = $project->workingDrawing()->sole();
    $this->actingAs($this->head)->post(route('design.working-drawings.assign', $drawing), ['pic_id' => $this->architect->id, 'start_date' => '2026-10-05', 'target_hari' => 2])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->architect)->put(route('design.working-drawings.links.update', $drawing), ['drawing_urls' => ['https://drive.google.com/gambar-kerja']])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->architect)->post(route('design.working-drawings.complete', $drawing))->assertSessionHasNoErrors();
    expect($project->fresh()->phase)->toBe(ProjectPhase::Tm);

    // The Admin Proyek fills the TM's timeline (input, Sub 07); the PM writes the minutes and finishes it.
    $this->actingAs($this->admin)->post(route('milestones.store', $project), ['name' => 'Produksi Dapur', 'start_date' => '2026-10-06', 'target_date' => '2026-10-31'])
        ->assertSessionHasNoErrors();
    $timeline = Milestone::sole();
    // The Pengawas's bahan (the planner itself is covered by Sub 11's tests).
    $project->projectMaterials()->create(['material_id' => Material::factory()->create()->id, 'unit_id' => unitId('pcs'), 'source' => 'GUDANG', 'qty_planned' => 2, 'request_status' => 'DISETUJUI']);
    $this->actingAs($this->pm)->put(route('projects.technical-meeting.save', $project), [
        'meeting_date' => '2026-10-06', 'notes' => 'Urutan kerja per item, finishing HPL, pengecekan tiap Jumat.',
        'attendees' => [['user_id' => $this->pm->id], ['user_id' => $this->qa->id], ['name' => 'Putra']],
        'dp_percent' => 10, 'end_date' => '2026-11-30',
    ])->assertSessionHasNoErrors();
    // Sub 03 / H3 — "TM Selesai" is the PM's, never the Admin Proyek's, and needs nothing of the Pengawas.
    $this->actingAs($this->admin)->post(route('projects.technical-meeting.complete', $project))->assertForbidden();
    $this->actingAs($this->pm)->post(route('projects.technical-meeting.complete', $project))->assertSessionHasNoErrors();
    expect($project->fresh()->phase)->toBe(ProjectPhase::Persiapan);

    // ── Upah Tukang per item RAB (File A: total Rp 7.705.000) ──────────────
    $wages = ['Kabinet bawah' => 500_000, 'Kabinet atas kanan' => 500_000, 'Kabinet atas kiri' => 500_000, 'Cover kulkas' => 300_000, 'Cover atas HPL' => 300_000, 'Meja bar' => 1_100_000];
    foreach ($wages as $description => $wage) {
        // Both Pengawas and the Admin Proyek may fill it; the PM may not (B3).
        $who = match ($description) {
            'Meja bar' => $this->admin, 'Cover kulkas' => $this->qa2, default => $this->qa
        };
        $this->actingAs($who)->put(route('projects.work-items.update', journeyItem($project, $description)), ['unit_wage' => $wage])->assertSessionHasNoErrors();
    }
    $this->actingAs($this->pm)->put(route('projects.work-items.update', journeyItem($project, 'Kabinet bawah')), ['unit_wage' => 1])->assertForbidden();
    expect(app(WorkItemService::class)->totalWage($project))->toBe(7_705_000.0);

    // Daily target by the Pengawas → DP 10 % → the PM approves = Start Produksi → Finance pays.
    $this->actingAs($this->qa)->post(route('tasks.store', $project), [
        'milestone_id' => $timeline->id, 'title' => 'Rakit kabinet bawah', 'assignee_id' => $this->tukang->id, 'due_date' => '2026-10-07',
    ])->assertSessionHasNoErrors();
    $target = Task::sole();
    $this->actingAs($this->admin)->post(route('projects.supervisor-payments.dp', $project))->assertForbidden();
    $this->actingAs($this->qa)->post(route('projects.supervisor-payments.dp', $project))->assertSessionHasNoErrors();
    $dp = SupervisorPayment::sole();
    expect($dp->kind)->toBe(SupervisorPaymentKind::Dp)
        ->and((float) $dp->amount)->toBe(770_500.0);
    $this->actingAs($this->admin)->post(route('supervisor-payments.decide', $dp), ['decision' => 'approve'])->assertForbidden();
    $this->actingAs($this->pm)->post(route('supervisor-payments.decide', $dp), ['decision' => 'approve'])->assertSessionHasNoErrors();
    expect($project->fresh()->phase)->toBe(ProjectPhase::Produksi);
    $this->actingAs($this->finance)->post(route('finance.supervisor-payments.pay', $dp), ['bank_account_id' => $this->bank->id])->assertSessionHasNoErrors();
    expect($dp->fresh()->status)->toBe(SupervisorPaymentStatus::Dibayar);

    // ── Daily target: the Tukang's "Selesai" waits; the second Pengawas approves (Sub 04/06) ──
    journeyClock('2026-10-07', '16:00:00');
    $this->actingAs($this->tukang)->patch(route('tasks.updateStatus', $target), ['status' => 'DONE'])->assertSessionHasNoErrors();
    expect($target->fresh()->status)->toBe(TaskStatus::Pengecekan);
    $this->actingAs($this->otherQa)->post(route('tasks.approve', $target))->assertForbidden();
    $this->actingAs($this->qa2)->post(route('tasks.approve', $target))->assertSessionHasNoErrors();
    expect($target->fresh()->status)->toBe(TaskStatus::Done);

    // ── Pengecekan % per item (File B "Contoh Putra") → pencairan ─────────
    journeyClock('2026-10-09', '15:00:00');
    $this->actingAs($this->qa)->post(route('projects.weekly-reports.store', $project), [
        'notes' => 'Kabinet & cover terpasang 70 %, meja bar 65 %.',
        'progress' => journeyCheck($project, ['Kabinet bawah' => 70, 'Kabinet atas kanan' => 70, 'Kabinet atas kiri' => 70, 'Cover kulkas' => 70, 'Cover atas HPL' => 70, 'Meja bar' => 65]),
    ])->assertSessionHasNoErrors();
    $weekly = SupervisorPayment::where('kind', SupervisorPaymentKind::Mingguan->value)->sole();
    expect(app(WorkItemService::class)->weightedProgress($project))->toBe(69.48)
        ->and((float) $weekly->amount)->toBe(4_551_500.0)
        // 69,48 % < 70 % — not yet "Berangkat ke Lokasi".
        ->and(Notification::where('user_id', $this->pm->id)->where('type', 'project_progress_reached')->count())->toBe(0);
    $this->actingAs($this->pm)->post(route('weekly-reports.decide', WeeklyReport::sole()), ['decision' => 'approve', 'amount' => 4_551_500])->assertSessionHasNoErrors();
    $this->actingAs($this->finance)->post(route('finance.supervisor-payments.pay', $weekly), ['bank_account_id' => $this->bank->id])->assertSessionHasNoErrors();
    $this->actingAs($this->pm)->post(route('projects.departure.approve', $project))->assertSessionHasErrors();

    // Next week: every item to 100 % → 70 % passed (the PM told once) → Berangkat → pelunasan asked.
    journeyClock('2026-10-16', '15:00:00');
    $this->actingAs($this->qa2)->post(route('projects.weekly-reports.store', $project), [
        'notes' => 'Semua item selesai dan terpasang.',
        'progress' => journeyCheck($project, ['Kabinet bawah' => 30, 'Kabinet atas kanan' => 30, 'Kabinet atas kiri' => 30, 'Cover kulkas' => 30, 'Cover atas HPL' => 30, 'Meja bar' => 35]),
    ])->assertSessionHasNoErrors();
    expect($project->fresh()->actualProgress())->toBe(100.0)
        ->and(Notification::where('user_id', $this->pm->id)->where('type', 'project_progress_reached')->count())->toBe(1);
    $settlement = SupervisorPayment::where('kind', SupervisorPaymentKind::Pelunasan->value)->sole();
    expect((float) $settlement->amount)->toBe(7_705_000.0 - 770_500.0 - 4_551_500.0);
    $this->actingAs($this->pm)->post(route('weekly-reports.decide', WeeklyReport::latest('id')->first()), ['decision' => 'approve', 'amount' => (float) $settlement->amount])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->finance)->post(route('finance.supervisor-payments.pay', $settlement), ['bank_account_id' => $this->bank->id])->assertSessionHasNoErrors();
    expect((float) SupervisorPayment::where('status', SupervisorPaymentStatus::Dibayar->value)->sum('amount'))->toBe(7_705_000.0);
    $this->actingAs($this->pm)->post(route('projects.departure.approve', $project))->assertSessionHasNoErrors();
    expect($project->fresh()->phase)->toBe(ProjectPhase::Pemasangan);

    // ── QA per item RAB (Sub 12) → COMPLETED ──────────────────────────────
    $inbox = collect(app(ActionInboxService::class)->for($this->qa2, fresh: true))->firstWhere('key', 'item-qa-pending');
    expect($inbox['items'][0]['subtitle'])->toBe('6 item menunggu QA');
    $itemIds = $project->workItems()->where('unit_wage', '>', 0)->pluck('id')->all();
    $this->actingAs($this->admin)->post(route('projects.item-qa.decide', $project), ['decision' => 'LOLOS', 'item_ids' => $itemIds])->assertForbidden();
    $this->actingAs($this->otherQa)->post(route('projects.item-qa.decide', $project), ['decision' => 'LOLOS', 'item_ids' => $itemIds])->assertForbidden();
    $this->actingAs($this->qa2)->post(route('projects.item-qa.decide', $project), ['decision' => 'LOLOS', 'item_ids' => $itemIds])->assertSessionHasNoErrors();

    expect($project->fresh()->status)->toBe(ProjectStatus::Completed)
        // The timeline was never "Tandai Selesai"-ed — QA per item decides (G2/G3).
        ->and($timeline->fresh()->qaForm)->toBeNull();
});
