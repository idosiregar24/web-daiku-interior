<?php

use App\Enums\FinanceCategory;
use App\Enums\LeadStatus;
use App\Enums\ProjectPhase;
use App\Enums\ProjectStatus;
use App\Enums\QuotationStatus;
use App\Enums\SiteFindingStatus;
use App\Enums\SupervisorPaymentKind;
use App\Enums\SupervisorPaymentStatus;
use App\Enums\TaskStatus;
use App\Enums\WeeklyReportStatus;
use App\Enums\WorkingDrawingStatus;
use App\Models\BankAccount;
use App\Models\DailyTaskForm;
use App\Models\FinanceTransaction;
use App\Models\Lead;
use App\Models\Material;
use App\Models\Milestone;
use App\Models\Notification;
use App\Models\Penalty;
use App\Models\Project;
use App\Models\ProjectOpening;
use App\Models\QaForm;
use App\Models\Quotation;
use App\Models\QuotationApproval;
use App\Models\SiteFinding;
use App\Models\SupervisorFee;
use App\Models\SupervisorPayment;
use App\Models\Task;
use App\Models\TaskScheduleChange;
use App\Models\TechnicalMeeting;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WorkingDrawing;
use App\Services\ActionInboxService;
use App\Services\MilestoneService;
use App\Services\PenaltyService;
use App\Services\ProductionService;
use App\Services\ProjectService;
use App\Services\QaFormService;
use App\Services\QuotationService;
use App\Services\TaskService;
use App\Services\WeeklyReportService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 24 — alur eksekusi proyek: Gambar Kerja → TM → syarat & DP
 * Pengawas → Produksi (daily target / actual activity, bergulir, geser) →
 * laporan mingguan → 70 % & berangkat ke lokasi → temuan finishing → H-30%.
 */

beforeEach(function () {
    // Wednesday, a working day.
    Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', 'Asia/Jakarta'));
    $this->seed(RoleSeeder::class);
    $this->ceo = execUser('CEO');
    $this->pm = execUser('PM');
    $this->otherPm = execUser('PM');
    $this->qa = execUser('QA');
    $this->otherQa = execUser('QA');
    $this->head = execUser('DESIGNER');
    $this->head->assignRole('KEPALA_DESAIN');
    $this->architect = execUser('DESIGNER');
    $this->finance = execUser('FINANCE');
    $this->logistics = execUser('LOGISTICS');
    $this->marketing = execUser('MARKETING');
    $this->tukang = execUser('FIELD_STAFF');
});

afterEach(fn () => Carbon::setTestNow());

function execUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** A project of $test->pm with $test->qa as Pengawas, at `$phase`, running 12 → 31 Oct. */
function execProject(object $test, ProjectPhase $phase, array $overrides = []): Project
{
    $project = Project::factory()->create([
        'pm_id' => $test->pm->id,
        'supervisor_id' => $test->qa->id,
        'phase' => $phase->value,
        'start_date' => '2026-10-12',
        'end_date' => '2026-10-31',
        ...$overrides,
    ]);

    if ($phase === ProjectPhase::GambarKerja) {
        WorkingDrawing::create(['project_id' => $project->id, 'status' => WorkingDrawingStatus::MenungguPenugasan->value]);
    }

    return $project;
}

function execTimeline(Project $project, string $start = '2026-10-12', string $end = '2026-10-24'): Milestone
{
    return Milestone::factory()->create(['project_id' => $project->id, 'start_date' => $start, 'target_date' => $end]);
}

function execTarget(Project $project, Milestone $milestone, User $tukang, array $overrides = []): Task
{
    return Task::factory()->create([
        'project_id' => $project->id,
        'milestone_id' => $milestone->id,
        'assignee_id' => $tukang->id,
        'due_date' => '2026-10-14',
        'planned_date' => '2026-10-14',
        'rate_per_task' => 150_000,
        ...$overrides,
    ]);
}

function execPlanMaterial(Project $project): void
{
    $project->projectMaterials()->create([
        'material_id' => Material::factory()->create()->id,
        'unit_id' => unitId('pcs'),
        'source' => 'GUDANG',
        'qty_planned' => 2,
        'request_status' => 'DISETUJUI',
    ]);
}

function notified(User $user, string $type): int
{
    return Notification::where('user_id', $user->id)->where('type', $type)->count();
}

// ── Sub 01–02: Buka Proyek → Gambar Kerja ────────────────────────────────

test('Buka Proyek starts the project at Gambar Kerja, in the Kepala Desain queue', function () {
    $lead = Lead::factory()->create(['status' => LeadStatus::DealDesain->value, 'assigned_to' => $this->marketing->id]);
    $service = app(QuotationService::class);
    $quotation = Quotation::factory()->create(['lead_id' => $lead->id, 'type' => 'PROYEK', 'status' => QuotationStatus::Draft->value]);
    $service->saveRab($quotation, ['items' => [['description' => 'Kitchen set', 'qty' => 1, 'unit_id' => unitId('set'), 'unit_price' => 50_000_000]]]);
    $service->savePaymentTerms($quotation, [['label' => 'DP', 'percentage' => 100, 'trigger' => 'DI_MUKA']]);
    QuotationApproval::create(['quotation_id' => $quotation->id, 'version' => $quotation->fresh()->version, 'approver_id' => $this->pm->id, 'approver_role' => 'PM', 'status' => 'APPROVED']);
    $quotation->update(['status' => QuotationStatus::SentToClient->value, 'valid_until' => '2026-10-30']);
    $service->clientApprove(shareLinkFor($quotation->fresh(), $this->marketing), true, '10.0.0.1', 'Test');

    $project = app(ProjectService::class)->openFromQuotation(ProjectOpening::where('quotation_id', $quotation->id)->sole(), [
        'name' => 'Proyek Uji', 'pm_id' => $this->pm->id, 'team_lead_name' => 'Tim A', 'supervisor_id' => $this->qa->id,
        'opened_on' => '2026-10-14', 'start_date' => '2026-10-15',
    ], $this->pm);

    expect($project->fresh()->phase)->toBe(ProjectPhase::GambarKerja)
        ->and($project->workingDrawing->status)->toBe(WorkingDrawingStatus::MenungguPenugasan)
        ->and(notified($this->head, 'working_drawing_to_assign'))->toBe(1);

    $inbox = collect(app(ActionInboxService::class)->for($this->head, true))->pluck('key');
    expect($inbox)->toContain('working-drawing-assign');
});

test('projects opened before Sprint 24 stay in production (column default)', function () {
    expect(Project::factory()->create()->fresh()->phase)->toBe(ProjectPhase::Produksi);
});

test('Gambar Kerja: Kepala Desain assigns, the architect saves links and finishes it → TM', function () {
    $project = execProject($this, ProjectPhase::GambarKerja);
    $drawing = $project->workingDrawing;

    $this->actingAs($this->architect)->post(route('design.working-drawings.assign', $drawing), [])->assertForbidden();
    $this->actingAs($this->head)->post(route('design.working-drawings.assign', $drawing), [
        'pic_id' => $this->architect->id, 'start_date' => '2026-10-14', 'target_hari' => 3,
    ])->assertSessionHasNoErrors();

    expect($drawing->fresh()->status)->toBe(WorkingDrawingStatus::Dikerjakan)
        ->and(notified($this->architect, 'working_drawing_assigned'))->toBe(1);

    // Finishing needs a link first; another architect isn't on the team.
    $this->actingAs($this->architect)->post(route('design.working-drawings.complete', $drawing))->assertSessionHasErrors('status');
    $outsider = execUser('DESIGNER');
    $this->actingAs($outsider)->put(route('design.working-drawings.links.update', $drawing), ['drawing_urls' => ['https://drive.google.com/x']])->assertForbidden();

    $this->actingAs($this->architect)->put(route('design.working-drawings.links.update', $drawing), ['drawing_urls' => ['https://drive.google.com/x']])->assertSessionHasNoErrors();
    $this->actingAs($this->architect)->post(route('design.working-drawings.complete', $drawing))->assertSessionHasNoErrors();

    expect($drawing->fresh()->status)->toBe(WorkingDrawingStatus::Selesai)
        ->and($drawing->fresh()->completed_by)->toBe($this->architect->id)
        ->and($project->fresh()->phase)->toBe(ProjectPhase::Tm)
        ->and(notified($this->pm, 'working_drawing_completed'))->toBe(1)
        ->and(notified($this->qa, 'working_drawing_completed'))->toBe(1);

    // Final: the links can't change any more.
    $this->actingAs($this->architect)->put(route('design.working-drawings.links.update', $drawing), ['drawing_urls' => ['https://drive.google.com/y']])->assertSessionHasErrors('drawing_urls');
});

test('Gambar Kerja pages: design team, PM, QA and CEO read; Marketing and Finance do not', function (string $role, int $status) {
    $project = execProject($this, ProjectPhase::GambarKerja);

    $this->actingAs(execUser($role))->get(route('design.working-drawings.index'))->assertStatus($status);
    $this->actingAs(execUser($role))->get(route('design.working-drawings.show', $project->workingDrawing))->assertStatus($status);
})->with([
    ['CEO', 200], ['PM', 200], ['QA', 200], ['MARKETING', 403], ['FINANCE', 403], ['FIELD_STAFF', 403],
]);

test('a plain architect opens only the Gambar Kerja they work on', function () {
    $project = execProject($this, ProjectPhase::GambarKerja);

    $this->actingAs($this->architect)->get(route('design.working-drawings.show', $project->workingDrawing))->assertForbidden();
    $this->actingAs($this->head)->get(route('design.working-drawings.show', $project->workingDrawing))->assertOk();
});

// ── Sub 03: Technical Meeting ────────────────────────────────────────────

test('TM: the PM records it, the Pengawas plans materials, "TM Selesai" needs every item → PERSIAPAN', function () {
    $project = execProject($this, ProjectPhase::Tm, ['end_date' => null]);
    $payload = [
        'meeting_date' => '2026-10-14',
        'notes' => 'Urutan kerja disepakati.',
        'attendees' => [['user_id' => $this->qa->id], ['name' => 'Pak Darto (vendor)']],
        'fee_total' => 5_000_000,
        'dp_percent' => 10,
        'end_date' => '',
    ];

    $this->actingAs($this->qa)->put(route('projects.technical-meeting.save', $project), $payload)->assertForbidden();
    $this->actingAs($this->otherPm)->put(route('projects.technical-meeting.save', $project), $payload)->assertForbidden();
    $this->actingAs($this->pm)->put(route('projects.technical-meeting.save', $project), $payload)->assertSessionHasNoErrors();

    $meeting = TechnicalMeeting::where('project_id', $project->id)->sole();
    expect($meeting->attendees()->count())->toBe(2)
        ->and((float) SupervisorFee::where('project_id', $project->id)->value('total_amount'))->toBe(5_000_000.0);

    // No timeline, no material, no Tanggal Selesai yet.
    $this->actingAs($this->pm)->post(route('projects.technical-meeting.complete', $project))->assertSessionHasErrors('tm');

    execTimeline($project);
    $material = Material::factory()->create();
    $this->actingAs($this->otherQa)->post(route('projects.materials.store', $project), ['material_id' => $material->id, 'source' => 'GUDANG', 'qty_planned' => 3])->assertForbidden();
    $this->actingAs($this->qa)->post(route('projects.materials.store', $project), ['material_id' => $material->id, 'source' => 'GUDANG', 'qty_planned' => 3])->assertSessionHasNoErrors();
    $this->actingAs($this->pm)->put(route('projects.technical-meeting.save', $project), [...$payload, 'end_date' => '2026-10-31'])->assertSessionHasNoErrors();

    $this->actingAs($this->pm)->post(route('projects.technical-meeting.complete', $project))->assertSessionHasNoErrors();

    expect($project->fresh()->phase)->toBe(ProjectPhase::Persiapan)
        ->and($project->fresh()->end_date->toDateString())->toBe('2026-10-31')
        ->and(notified($this->qa, 'technical_meeting_completed'))->toBe(1);
});

test('the TM form is refused outside the TM phase', function () {
    $project = execProject($this, ProjectPhase::Produksi);

    $this->actingAs($this->pm)->put(route('projects.technical-meeting.save', $project), [
        'meeting_date' => '2026-10-14', 'fee_total' => 1, 'dp_percent' => 10,
    ])->assertSessionHasErrors('phase');
});

// ── Sub 05: Daily Target ─────────────────────────────────────────────────

test('daily targets: only after the TM, by the Pengawas too, always inside a timeline', function () {
    $project = execProject($this, ProjectPhase::Tm);
    $milestone = execTimeline($project);
    $target = ['milestone_id' => $milestone->id, 'title' => 'Potong panel', 'assignee_id' => $this->tukang->id, 'due_date' => '2026-10-15', 'rate_per_task' => 100_000];

    $this->actingAs($this->qa)->post(route('tasks.store', $project), $target)->assertSessionHasErrors('title');

    $project->update(['phase' => ProjectPhase::Persiapan->value]);
    $this->actingAs($this->otherQa)->post(route('tasks.store', $project), $target)->assertForbidden();
    $this->actingAs($this->qa)->post(route('tasks.store', $project), [...$target, 'due_date' => '2026-10-30'])->assertSessionHasErrors('due_date');
    $this->actingAs($this->qa)->post(route('tasks.store', $project), [...$target, 'milestone_id' => null])->assertSessionHasErrors('milestone_id');
    $this->actingAs($this->qa)->post(route('tasks.store', $project), $target)->assertSessionHasNoErrors();

    $task = Task::where('project_id', $project->id)->sole();
    expect($task->planned_date->toDateString())->toBe('2026-10-15')
        ->and($task->created_by)->toBe($this->qa->id);
});

test('the Pengawas sees the daily targets of its own project only (PRD §4.6 elsewhere)', function () {
    $mine = execProject($this, ProjectPhase::Produksi);
    $other = execProject($this, ProjectPhase::Produksi, ['supervisor_id' => $this->otherQa->id]);
    execTarget($mine, execTimeline($mine), $this->tukang);
    execTarget($other, execTimeline($other), $this->tukang);

    $this->actingAs($this->qa)->get(route('projects.show', $mine))
        ->assertInertia(fn (Assert $page) => $page->has('tasks', 1)->where('canManageTasks', true)->whereNot('execution', null));
    $this->actingAs($this->qa)->get(route('projects.show', $other))
        ->assertInertia(fn (Assert $page) => $page->has('tasks', 0)->where('canManageTasks', false));
});

test('the Tukang works on targets only once production started', function () {
    $project = execProject($this, ProjectPhase::Persiapan);
    $task = execTarget($project, execTimeline($project), $this->tukang);

    $this->actingAs($this->tukang)->patch(route('tasks.updateStatus', $task), ['status' => 'ONPROGRESS'])->assertSessionHasErrors('status');

    $project->update(['phase' => ProjectPhase::Produksi->value]);
    $this->actingAs($this->tukang)->patch(route('tasks.updateStatus', $task), ['status' => 'ONPROGRESS'])->assertSessionHasNoErrors();
});

// ── Sub 04: DP Pengawas ──────────────────────────────────────────────────

test('DP: conditions first, the Pengawas asks, the PM approves (= Start Produksi), Finance pays', function () {
    $project = execProject($this, ProjectPhase::Persiapan);
    SupervisorFee::create(['project_id' => $project->id, 'supervisor_id' => $this->qa->id, 'total_amount' => 5_000_000, 'dp_percent' => 10]);
    $milestone = execTimeline($project);

    // No material, no targets yet.
    $this->actingAs($this->qa)->post(route('projects.supervisor-payments.dp', $project))->assertSessionHasErrors('dp');

    execPlanMaterial($project);
    execTarget($project, $milestone, $this->tukang, ['rate_per_task' => null]);
    $this->actingAs($this->qa)->post(route('projects.supervisor-payments.dp', $project))->assertSessionHasErrors('dp'); // wage missing
    Task::where('project_id', $project->id)->update(['rate_per_task' => 120_000]);

    $this->actingAs($this->otherQa)->post(route('projects.supervisor-payments.dp', $project))->assertForbidden();
    $this->actingAs($this->pm)->post(route('projects.supervisor-payments.dp', $project))->assertForbidden();
    $this->actingAs($this->qa)->post(route('projects.supervisor-payments.dp', $project))->assertSessionHasNoErrors();
    $this->actingAs($this->qa)->post(route('projects.supervisor-payments.dp', $project))->assertSessionHasErrors('dp'); // once

    $payment = SupervisorPayment::sole();
    expect($payment->kind)->toBe(SupervisorPaymentKind::Dp)
        ->and((float) $payment->amount)->toBe(500_000.0)
        ->and(notified($this->pm, 'supervisor_payment_requested'))->toBe(1);

    $this->actingAs($this->otherPm)->post(route('supervisor-payments.decide', $payment), ['decision' => 'approve'])->assertForbidden();
    $this->actingAs($this->pm)->post(route('supervisor-payments.decide', $payment), ['decision' => 'approve', 'amount' => 6_000_000])->assertSessionHasErrors('amount');
    $this->actingAs($this->pm)->post(route('supervisor-payments.decide', $payment), ['decision' => 'approve', 'amount' => 600_000])->assertSessionHasNoErrors();

    $project->refresh();
    expect($payment->fresh()->status)->toBe(SupervisorPaymentStatus::DisetujuiPm)
        ->and((float) $payment->fresh()->amount)->toBe(600_000.0)
        ->and($project->phase)->toBe(ProjectPhase::Produksi)
        ->and($project->production_started_at)->not->toBeNull()
        ->and(notified($this->logistics, 'production_started'))->toBe(1)
        ->and(notified($this->finance, 'supervisor_payment_to_pay'))->toBe(1)
        ->and(collect(app(ActionInboxService::class)->for($this->finance, true))->pluck('key'))->toContain('supervisor-pay');

    $account = BankAccount::factory()->create(['is_active' => true]);
    $this->actingAs($this->pm)->post(route('finance.supervisor-payments.pay', $payment), ['bank_account_id' => $account->id])->assertForbidden();
    $this->actingAs($this->finance)->get(route('finance.supervisor-payments.index'))->assertOk();
    $this->actingAs($this->finance)->post(route('finance.supervisor-payments.pay', $payment), ['bank_account_id' => $account->id])->assertSessionHasNoErrors();

    $transaction = FinanceTransaction::sole();
    expect($payment->fresh()->status)->toBe(SupervisorPaymentStatus::Dibayar)
        ->and($transaction->kategori)->toBe(FinanceCategory::JasaPengawas)
        ->and((float) $transaction->amount)->toBe(600_000.0)
        ->and($transaction->reference_id)->toBe($payment->id)
        ->and(notified($this->qa, 'supervisor_payment_paid'))->toBe(1);

    $this->actingAs($this->finance)->post(route('finance.supervisor-payments.pay', $payment), ['bank_account_id' => $account->id])->assertSessionHasErrors('payment');
});

test('the Jasa Pengawas page: CEO and Finance only', function (string $role, int $status) {
    $this->actingAs(execUser($role))->get(route('finance.supervisor-payments.index'))->assertStatus($status);
})->with([['CEO', 200], ['FINANCE', 200], ['PM', 403], ['QA', 403], ['MARKETING', 403]]);

test('a rejected request needs a reason and may be asked again', function () {
    $project = execProject($this, ProjectPhase::Persiapan);
    $fee = SupervisorFee::create(['project_id' => $project->id, 'supervisor_id' => $this->qa->id, 'total_amount' => 2_000_000, 'dp_percent' => 10]);
    $payment = $fee->payments()->create(['project_id' => $project->id, 'supervisor_id' => $this->qa->id, 'kind' => 'DP', 'amount' => 200_000, 'status' => 'DIAJUKAN']);

    $this->actingAs($this->pm)->post(route('supervisor-payments.decide', $payment), ['decision' => 'reject'])->assertSessionHasErrors('note');
    $this->actingAs($this->pm)->post(route('supervisor-payments.decide', $payment), ['decision' => 'reject', 'note' => 'Upah belum lengkap'])->assertSessionHasNoErrors();

    expect($payment->fresh()->status)->toBe(SupervisorPaymentStatus::Ditolak)
        ->and($project->fresh()->phase)->toBe(ProjectPhase::Persiapan)
        ->and(notified($this->qa, 'supervisor_payment_decided'))->toBe(1);
});

test('the pelunasan is the rest of the fee, once the project is completed', function () {
    $project = execProject($this, ProjectPhase::Pemasangan);
    $fee = SupervisorFee::create(['project_id' => $project->id, 'supervisor_id' => $this->qa->id, 'total_amount' => 3_000_000, 'dp_percent' => 10]);
    $fee->payments()->create(['project_id' => $project->id, 'supervisor_id' => $this->qa->id, 'kind' => 'DP', 'amount' => 300_000, 'status' => 'DIBAYAR']);

    $this->actingAs($this->qa)->post(route('projects.supervisor-payments.settlement', $project))->assertSessionHasErrors('settlement');

    $project->update(['status' => ProjectStatus::Completed->value]);
    $this->actingAs($this->qa)->post(route('projects.supervisor-payments.settlement', $project))->assertSessionHasNoErrors();

    $settlement = SupervisorPayment::where('kind', SupervisorPaymentKind::Pelunasan->value)->sole();
    expect((float) $settlement->amount)->toBe(2_700_000.0);
});

// ── Sub 06: Actual Activity & penalty ────────────────────────────────────

test('the Tukang\'s own check-in is the day\'s report; penalty only for untouched targets of today', function () {
    $project = execProject($this, ProjectPhase::Produksi);
    $milestone = execTimeline($project);
    $checked = execTarget($project, $milestone, $this->tukang);
    $lazy = execUser('FIELD_STAFF');
    execTarget($project, $milestone, $lazy);
    $future = execUser('FIELD_STAFF');
    execTarget($project, $milestone, $future, ['due_date' => '2026-10-20']);
    // A target on a project that isn't producing yet doesn't count.
    $waiting = execUser('FIELD_STAFF');
    $prep = execProject($this, ProjectPhase::Persiapan);
    execTarget($prep, execTimeline($prep), $waiting);

    $this->actingAs($this->tukang)->patch(route('tasks.updateStatus', $checked), ['status' => 'DONE'])->assertSessionHasNoErrors();

    expect(DailyTaskForm::where('task_id', $checked->id)->forDate('2026-10-14')->exists())->toBeTrue()
        ->and(app(PenaltyService::class)->staffMissingDailyForm(now('Asia/Jakarta'))->pluck('id')->all())->toBe([$lazy->id]);

    Carbon::setTestNow(Carbon::parse('2026-10-14 21:00:00', 'Asia/Jakarta'));
    expect(app(PenaltyService::class)->runDailyCheck())->toBe(1)
        ->and(Penalty::where('staff_id', $lazy->id)->exists())->toBeTrue();
});

test('a second check-in the same day updates the day\'s row (kendala counts as a report)', function () {
    $project = execProject($this, ProjectPhase::Produksi);
    $task = execTarget($project, execTimeline($project), $this->tukang);

    $this->actingAs($this->tukang)->patch(route('tasks.updateStatus', $task), ['status' => 'ONPROGRESS', 'kendala' => 'Bahan belum datang']);
    $this->actingAs($this->tukang)->patch(route('tasks.updateStatus', $task), ['status' => 'ONPROGRESS', 'kendala' => 'Bahan datang siang']);

    expect(DailyTaskForm::where('task_id', $task->id)->count())->toBe(1)
        ->and(DailyTaskForm::where('task_id', $task->id)->value('kendala'))->toBe('Bahan datang siang');
});

// ── Sub 07: Bergulir & geser ─────────────────────────────────────────────

test('an unfinished target rolls to the next working day inside its timeline, else goes OVER', function () {
    $project = execProject($this, ProjectPhase::Produksi);
    $open = execTimeline($project, '2026-10-12', '2026-10-24');
    $closing = execTimeline($project, '2026-10-12', '2026-10-13');
    $rolls = execTarget($project, $open, $this->tukang, ['due_date' => '2026-10-13', 'status' => 'ONPROGRESS']);
    $late = execTarget($project, $closing, $this->tukang, ['due_date' => '2026-10-13']);

    expect(app(TaskService::class)->markOverdueTasks())->toBe(2);

    expect($rolls->fresh()->due_date->toDateString())->toBe('2026-10-14')
        ->and($rolls->fresh()->rollover_count)->toBe(1)
        ->and($rolls->fresh()->planned_date->toDateString())->toBe('2026-10-14')
        ->and($rolls->fresh()->status)->toBe(TaskStatus::OnProgress)
        ->and(TaskScheduleChange::where('task_id', $rolls->id)->where('kind', 'ROLLOVER')->exists())->toBeTrue()
        ->and($late->fresh()->status)->toBe(TaskStatus::Over)
        ->and(notified($this->qa, 'task_overdue'))->toBe(1)
        ->and(app(TaskService::class)->markOverdueTasks())->toBe(0);
});

test('Saturday rolls to the next working day, never to Sunday', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-18 00:00:00', 'Asia/Jakarta')); // Sunday midnight
    $project = execProject($this, ProjectPhase::Produksi);
    $task = execTarget($project, execTimeline($project), $this->tukang, ['due_date' => '2026-10-17']);

    app(TaskService::class)->markOverdueTasks();

    expect($task->fresh()->due_date->toDateString())->toBe('2026-10-19');
});

test('geser: the Pengawas moves a target inside its timeline only; the Tukang never', function () {
    $project = execProject($this, ProjectPhase::Produksi);
    $task = execTarget($project, execTimeline($project), $this->tukang);

    $this->actingAs($this->tukang)->patch(route('tasks.reschedule', $task), ['due_date' => '2026-10-16'])->assertForbidden();
    $this->actingAs($this->otherQa)->patch(route('tasks.reschedule', $task), ['due_date' => '2026-10-16'])->assertForbidden();
    $this->actingAs($this->qa)->patch(route('tasks.reschedule', $task), ['due_date' => '2026-10-30'])->assertSessionHasErrors('due_date');
    $this->actingAs($this->qa)->patch(route('tasks.reschedule', $task), ['due_date' => '2026-10-16', 'note' => 'Bahan telat'])->assertSessionHasNoErrors();

    expect($task->fresh()->due_date->toDateString())->toBe('2026-10-16')
        ->and($task->fresh()->planned_date->toDateString())->toBe('2026-10-14')
        ->and(TaskScheduleChange::where('task_id', $task->id)->where('kind', 'GESER')->value('note'))->toBe('Bahan telat')
        ->and(notified($this->tukang, 'task_updated'))->toBe(1);
});

test('tukar: two days of targets swap, refused as a whole when one would leave its timeline', function () {
    $project = execProject($this, ProjectPhase::Produksi);
    $milestone = execTimeline($project, '2026-10-12', '2026-10-20');
    $a = execTarget($project, $milestone, $this->tukang, ['due_date' => '2026-10-15']);
    $b = execTarget($project, $milestone, $this->tukang, ['due_date' => '2026-10-16']);

    $this->actingAs($this->qa)->post(route('projects.targets.swap', $project), ['from_date' => '2026-10-15', 'to_date' => '2026-10-16'])->assertSessionHasNoErrors();
    expect($a->fresh()->due_date->toDateString())->toBe('2026-10-16')
        ->and($b->fresh()->due_date->toDateString())->toBe('2026-10-15');

    $this->actingAs($this->qa)->post(route('projects.targets.swap', $project), ['from_date' => '2026-10-15', 'to_date' => '2026-10-23'])->assertSessionHasErrors('to_date');
    expect($b->fresh()->due_date->toDateString())->toBe('2026-10-15');

    $this->actingAs($this->marketing)->post(route('projects.targets.swap', $project), ['from_date' => '2026-10-15', 'to_date' => '2026-10-16'])->assertForbidden();
});

// ── Sub 08: Laporan mingguan ─────────────────────────────────────────────

test('weekly report: the Pengawas sends it, the PM rejects with a reason or approves with a payment', function () {
    $project = execProject($this, ProjectPhase::Produksi, ['production_started_at' => '2026-10-05 08:00:00']);
    SupervisorFee::create(['project_id' => $project->id, 'supervisor_id' => $this->qa->id, 'total_amount' => 4_000_000, 'dp_percent' => 10]);

    $this->actingAs($this->otherQa)->post(route('projects.weekly-reports.store', $project), ['notes' => 'Progres baik'])->assertForbidden();
    $this->actingAs($this->qa)->post(route('projects.weekly-reports.store', $project), ['notes' => 'Progres baik', 'links' => ['https://drive.google.com/r']])->assertSessionHasNoErrors();
    $this->actingAs($this->qa)->post(route('projects.weekly-reports.store', $project), ['notes' => 'Lagi'])->assertSessionHasErrors('notes');

    $report = WeeklyReport::sole();
    expect($report->week_number)->toBe(2)
        ->and($report->week_start->toDateString())->toBe('2026-10-12')
        ->and(notified($this->pm, 'weekly_report_submitted'))->toBe(1);

    $this->actingAs($this->qa)->post(route('weekly-reports.decide', $report), ['decision' => 'approve'])->assertForbidden();
    $this->actingAs($this->pm)->post(route('weekly-reports.decide', $report), ['decision' => 'reject'])->assertSessionHasErrors('note');
    $this->actingAs($this->pm)->post(route('weekly-reports.decide', $report), ['decision' => 'reject', 'note' => 'Foto kurang'])->assertSessionHasNoErrors();
    expect($report->fresh()->status)->toBe(WeeklyReportStatus::Ditolak);

    $this->actingAs($this->qa)->post(route('projects.weekly-reports.store', $project), ['notes' => 'Foto lengkap'])->assertSessionHasNoErrors();
    expect(WeeklyReport::count())->toBe(1)->and($report->fresh()->status)->toBe(WeeklyReportStatus::Diajukan);

    $this->actingAs($this->pm)->post(route('weekly-reports.decide', $report), ['decision' => 'approve', 'amount' => 400_000])->assertSessionHasNoErrors();

    $payment = SupervisorPayment::sole();
    expect($report->fresh()->status)->toBe(WeeklyReportStatus::Disetujui)
        ->and($payment->kind)->toBe(SupervisorPaymentKind::Mingguan)
        ->and($payment->status)->toBe(SupervisorPaymentStatus::DisetujuiPm)
        ->and($payment->label)->toBe("Mingguan minggu ke-2 · {$project->name}"); // factory names start with "Proyek"
});

test('the Friday reminder reaches a Pengawas without this week\'s report, once a day', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-16 15:00:00', 'Asia/Jakarta'));
    $missing = execProject($this, ProjectPhase::Produksi);
    $sent = execProject($this, ProjectPhase::Pemasangan, ['supervisor_id' => $this->otherQa->id]);
    WeeklyReport::create(['project_id' => $sent->id, 'supervisor_id' => $this->otherQa->id, 'week_start' => '2026-10-12', 'week_number' => 1, 'notes' => 'ok', 'status' => 'DIAJUKAN']);
    execProject($this, ProjectPhase::Tm); // not producing

    $service = app(WeeklyReportService::class);

    expect($service->sendReminders())->toBe(1)
        ->and($service->sendReminders())->toBe(0)
        ->and(notified($this->qa, 'weekly_report_reminder'))->toBe(1)
        ->and(notified($this->otherQa, 'weekly_report_reminder'))->toBe(0);
});

// ── Sub 09: 70 % & berangkat ─────────────────────────────────────────────

test('70 % actual activity tells the PM once and unlocks "Berangkat ke Lokasi" → PEMASANGAN', function () {
    $project = execProject($this, ProjectPhase::Produksi);
    $milestone = execTimeline($project);
    $tasks = collect(range(1, 10))->map(fn () => execTarget($project, $milestone, $this->tukang));
    $tasks->take(6)->each(fn (Task $task) => $task->update(['status' => 'DONE']));

    $this->actingAs($this->pm)->post(route('projects.departure.approve', $project))->assertSessionHasErrors('departure');

    $this->actingAs($this->tukang)->patch(route('tasks.updateStatus', $tasks[6]), ['status' => 'DONE']);
    $this->actingAs($this->tukang)->patch(route('tasks.updateStatus', $tasks[7]), ['status' => 'DONE']);

    expect($project->fresh()->actualProgress())->toBe(80)
        ->and(notified($this->pm, 'project_progress_reached'))->toBe(1);

    $this->actingAs($this->otherPm)->post(route('projects.departure.approve', $project))->assertForbidden();
    $this->actingAs($this->qa)->post(route('projects.departure.approve', $project))->assertForbidden();
    $this->actingAs($this->pm)->post(route('projects.departure.approve', $project))->assertSessionHasNoErrors();

    expect($project->fresh()->phase)->toBe(ProjectPhase::Pemasangan)
        ->and($project->fresh()->departure_approved_by)->toBe($this->pm->id)
        ->and(notified($this->qa, 'site_departure_approved'))->toBe(1)
        ->and(notified($this->logistics, 'site_departure_approved'))->toBe(1);
});

// ── Sub 10: Temuan finishing ─────────────────────────────────────────────

test('findings at PEMASANGAN become repair tasks and hold the timeline\'s QA until fixed', function () {
    $project = execProject($this, ProjectPhase::Produksi);
    $milestone = execTimeline($project, '2026-10-01', '2026-10-10');
    $payload = ['description' => 'HPL pintu atas terkelupas', 'milestone_id' => $milestone->id, 'photo_url' => 'https://drive.google.com/f'];

    $this->actingAs($this->qa)->post(route('projects.site-findings.store', $project), $payload)->assertSessionHasErrors('description');

    $project->update(['phase' => ProjectPhase::Pemasangan->value]);
    $this->actingAs($this->otherQa)->post(route('projects.site-findings.store', $project), $payload)->assertForbidden();
    $this->actingAs($this->qa)->post(route('projects.site-findings.store', $project), $payload)->assertSessionHasNoErrors();
    $finding = SiteFinding::sole();
    expect(notified($this->pm, 'site_finding_recorded'))->toBe(1);

    // QA of the timeline is held while the finding is open.
    $milestone->update(['status' => 'QA_WAITING']);
    $form = QaForm::create(['project_id' => $project->id, 'milestone_id' => $milestone->id, 'status' => 'PENDING', 'checklist_data' => []]);
    expect(fn () => app(QaFormService::class)->review($form, 'approve', [], null, $this->qa))->toThrow(ValidationException::class);

    // Repair task after the timeline's end is allowed.
    $this->actingAs($this->qa)->post(route('site-findings.repair-task', $finding), ['assignee_id' => $this->tukang->id, 'due_date' => '2026-10-15'])->assertSessionHasNoErrors();
    $repair = Task::sole();
    expect($finding->fresh()->task_id)->toBe($repair->id)
        ->and($repair->milestone_id)->toBe($milestone->id);

    $this->actingAs($this->tukang)->patch(route('tasks.updateStatus', $repair), ['status' => 'DONE'])->assertSessionHasNoErrors();
    expect($finding->fresh()->status)->toBe(SiteFindingStatus::Selesai);

    app(QaFormService::class)->review($form->fresh(), 'approve', [], null, $this->qa);
    expect($form->fresh()->status->value)->toBe('APPROVED');
});

test('an open finding keeps the project from COMPLETED', function () {
    $project = execProject($this, ProjectPhase::Pemasangan);
    Milestone::factory()->create(['project_id' => $project->id, 'status' => 'COMPLETED']);
    $finding = SiteFinding::create(['project_id' => $project->id, 'description' => 'Lis plafon miring', 'status' => 'TERBUKA', 'reported_by' => $this->qa->id]);

    expect(app(ProjectService::class)->completeIfFinished($project))->toBeFalse();

    $this->actingAs($this->pm)->post(route('site-findings.resolve', $finding))->assertSessionHasNoErrors();
    expect(app(ProjectService::class)->completeIfFinished($project->fresh()))->toBeTrue();
});

// ── Sub 11: H-30% ────────────────────────────────────────────────────────

test('H-30%: from 70 % of the duration on, the PM is told every day (once a day)', function () {
    $project = execProject($this, ProjectPhase::Produksi, ['start_date' => '2026-10-01', 'end_date' => '2026-10-10']);
    $service = app(ProductionService::class);

    expect($service->deadlineWarningFrom($project)->toDateString())->toBe('2026-10-07')
        ->and($service->sendDeadlineReminders(Carbon::parse('2026-10-06', 'Asia/Jakarta')))->toBe(0)
        ->and($service->sendDeadlineReminders(Carbon::parse('2026-10-14', 'Asia/Jakarta')))->toBe(1)
        ->and($service->sendDeadlineReminders(Carbon::parse('2026-10-14', 'Asia/Jakarta')))->toBe(0)
        ->and(notified($this->pm, 'project_deadline_near'))->toBe(1);

    $project->update(['status' => ProjectStatus::Completed->value]);
    Carbon::setTestNow(Carbon::parse('2026-10-15 08:00:00', 'Asia/Jakarta'));
    expect($service->sendDeadlineReminders())->toBe(0);
});

// ── The Eksekusi tab & queues ────────────────────────────────────────────

test('the Eksekusi tab: money only for CEO / PM / Finance / the Pengawas, nothing for Marketing', function () {
    $project = execProject($this, ProjectPhase::Produksi);
    SupervisorFee::create(['project_id' => $project->id, 'supervisor_id' => $this->qa->id, 'total_amount' => 1_000_000, 'dp_percent' => 10]);

    $this->actingAs($this->pm)->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page->where('execution.phase', 'PRODUKSI')->whereNot('execution.fee', null)->where('execution.can.approveDeparture', true));
    $this->actingAs($this->logistics)->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page->where('execution.fee', null)->where('execution.payments', []));
    $this->actingAs($this->marketing)->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page->where('execution', null));
});

test('the PM\'s queue holds a project at TM; the Pengawas\'s a project waiting for its DP request', function () {
    execProject($this, ProjectPhase::Tm);
    execProject($this, ProjectPhase::Persiapan);

    expect(collect(app(ActionInboxService::class)->for($this->pm, true))->pluck('key'))->toContain('execution-pm')
        ->and(collect(app(ActionInboxService::class)->for($this->qa, true))->pluck('key'))->toContain('execution-supervisor')
        ->and(collect(app(ActionInboxService::class)->for($this->otherQa, true))->pluck('key'))->not->toContain('execution-supervisor');
});

test('changing the Pengawas in Ubah Proyek tells the new and the previous one', function () {
    $project = execProject($this, ProjectPhase::Produksi);

    app(ProjectService::class)->update($project, [
        'name' => $project->name, 'start_date' => '2026-10-12', 'end_date' => '2026-10-31',
        'contract_value' => $project->contract_value, 'status' => 'ACTIVE', 'supervisor_id' => $this->otherQa->id,
    ], $this->pm);

    expect($project->fresh()->supervisor_id)->toBe($this->otherQa->id)
        ->and(notified($this->otherQa, 'project_supervisor_assigned'))->toBe(1)
        ->and(notified($this->qa, 'project_supervisor_unassigned'))->toBe(1);
});

// ── D14: Pengawas = QA = QC — the project's Pengawas checks its timelines ──

test('only the project\'s Pengawas (QA/QC) is asked to check a timeline and may decide it', function () {
    $project = execProject($this, ProjectPhase::Produksi);
    $milestone = execTimeline($project);

    app(MilestoneService::class)->markDone($milestone);
    $form = QaForm::where('milestone_id', $milestone->id)->sole();
    $payload = ['decision' => 'approve', 'checklist_data' => [['label' => 'Sesuai gambar kerja', 'passed' => true]], 'notes' => null];

    expect(notified($this->qa, 'qa_form_created'))->toBe(1)
        ->and(notified($this->otherQa, 'qa_form_created'))->toBe(0)
        ->and(collect(app(ActionInboxService::class)->for($this->otherQa, true))->pluck('key'))->not->toContain('qa-pending');

    $this->actingAs($this->otherQa)->get(route('qa-forms.show', $form))->assertInertia(fn (Assert $page) => $page->where('canReview', false));
    $this->actingAs($this->otherQa)->put(route('qa-forms.update', $form), $payload)->assertForbidden();
    $this->actingAs($this->qa)->put(route('qa-forms.update', $form), $payload)->assertSessionHasNoErrors();

    expect($form->fresh()->reviewer_id)->toBe($this->qa->id);
});

test('a project without a Pengawas is still checked by any QA/QC user', function () {
    $project = execProject($this, ProjectPhase::Produksi, ['supervisor_id' => null]);
    $milestone = execTimeline($project);

    app(MilestoneService::class)->markDone($milestone);

    expect(notified($this->qa, 'qa_form_created'))->toBe(1)
        ->and(notified($this->otherQa, 'qa_form_created'))->toBe(1);
});
