<?php

use App\Enums\FinanceCategory;
use App\Enums\LeadStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectPhase;
use App\Enums\ProjectStatus;
use App\Enums\QuotationStatus;
use App\Enums\SiteFindingStatus;
use App\Enums\SupervisorPaymentKind;
use App\Enums\SupervisorPaymentStatus;
use App\Enums\TaskStatus;
use App\Enums\WeeklyReportStatus;
use App\Enums\WorkingDrawingStatus;
use App\Models\AuditLog;
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
use App\Models\ProjectWorkItem;
use App\Models\QaForm;
use App\Models\Quotation;
use App\Models\QuotationApproval;
use App\Models\QuotationItem;
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
use App\Services\RoleRedirectService;
use App\Services\TaskService;
use App\Services\WeeklyReportService;
use App\Services\WorkItemService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
    // Sprint 29 Sub 06 — the Pengawas sit in project_supervisors (`supervisor_id` override = that one, null = none).
    $supervisorId = array_key_exists('supervisor_id', $overrides) ? $overrides['supervisor_id'] : $test->qa->id;
    unset($overrides['supervisor_id']);

    $project = Project::factory()->create([
        'pm_id' => $test->pm->id,
        'phase' => $phase->value,
        'start_date' => '2026-10-12',
        'end_date' => '2026-10-31',
        ...$overrides,
    ]);

    if ($supervisorId) {
        $project->supervisors()->attach($supervisorId);
    }

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
        'name' => 'Proyek Uji', 'pm_id' => $this->pm->id, 'team_lead_name' => 'Tim A', 'supervisor_ids' => [$this->qa->id],
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

    // The PM's TM card lists the Pengawas's materials (read-only).
    $this->actingAs($this->pm)->get(route('projects.show', $project))->assertInertia(fn ($page) => $page
        ->where('execution.meetingMaterials.0.name', $material->name)
        ->count('execution.meetingMaterials', 1));

    $this->actingAs($this->pm)->post(route('projects.technical-meeting.complete', $project))->assertSessionHasNoErrors();

    expect($project->fresh()->phase)->toBe(ProjectPhase::Persiapan)
        ->and($project->fresh()->end_date->toDateString())->toBe('2026-10-31')
        ->and(notified($this->qa, 'technical_meeting_completed'))->toBe(1);
});

test('"TM Selesai" needs only the PM part — no materials, no fee; the DP conditions still ask for them (Sprint 29 Sub 03)', function () {
    $project = execProject($this, ProjectPhase::Tm, ['end_date' => null]);
    $timeline = execTimeline($project);

    // Date & minutes, an attendee, a timeline, Tanggal Selesai — no fee, no material.
    $this->actingAs($this->pm)->put(route('projects.technical-meeting.save', $project), [
        'meeting_date' => '2026-10-14',
        'notes' => 'Urutan kerja disepakati.',
        'attendees' => [['user_id' => $this->qa->id]],
        'fee_total' => '',
        'dp_percent' => 10,
        'end_date' => '2026-10-31',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->pm)->get(route('projects.show', $project))->assertInertia(fn ($page) => $page
        ->where('execution.meetingRequirements', fn ($rows) => collect($rows)->pluck('key')->all() === ['notes', 'attendees', 'timeline', 'end_date']
            && collect($rows)->every(fn ($row) => $row['done'])));

    $this->actingAs($this->pm)->post(route('projects.technical-meeting.complete', $project))->assertSessionHasNoErrors();
    expect($project->fresh()->phase)->toBe(ProjectPhase::Persiapan)
        ->and(SupervisorFee::where('project_id', $project->id)->exists())->toBeFalse();

    // PERSIAPAN: the Pengawas's part (+ the fee) still holds the DP.
    execTarget($project, $timeline, $this->tukang, ['rate_per_task' => 150_000]);
    $this->actingAs($this->qa)->get(route('projects.show', $project))->assertInertia(fn ($page) => $page
        ->where('execution.dpRequirements', fn ($rows) => collect($rows)->pluck('done', 'key')->all() === ['materials' => false, 'wages' => true, 'targets' => true, 'fee' => false]));
    $this->actingAs($this->qa)->post(route('projects.supervisor-payments.dp', $project))->assertSessionHasErrors();
    expect(SupervisorPayment::where('project_id', $project->id)->exists())->toBeFalse();
});

test('TM: the RAB items sit beside the materials — only the PM and the project Pengawas, never prices', function () {
    $project = execProject($this, ProjectPhase::Tm);
    $quotation = Quotation::factory()->create();
    QuotationItem::factory()->create(['quotation_id' => $quotation->id, 'description' => 'Kitchen Set Atas', 'qty' => 2.5]);
    $project->update(['quotation_id' => $quotation->id]);

    foreach ([$this->pm, $this->qa] as $viewer) {
        $this->actingAs($viewer)->get(route('projects.show', $project))->assertInertia(fn ($page) => $page
            ->where('execution.meetingRab.0.items.0.description', 'Kitchen Set Atas')
            ->where('execution.meetingRab.0.items.0.volume', fn ($volume) => str_starts_with($volume, '2,5'))
            ->missing('execution.meetingRab.0.items.0.unit_price')
            ->missing('execution.meetingRab.0.items.0.total_price'));
    }

    foreach ([$this->otherQa, $this->finance] as $viewer) {
        $this->actingAs($viewer)->get(route('projects.show', $project))->assertInertia(fn ($page) => $page->count('execution.meetingRab', 0));
    }
});

test('TM schedule: the PM saves the date before the fee, the Pengawas and attendees hear it (again when moved)', function () {
    $project = execProject($this, ProjectPhase::Tm);
    $schedule = ['meeting_date' => '2026-10-20', 'attendees' => [['user_id' => $this->logistics->id]], 'fee_total' => '', 'dp_percent' => 10];

    $this->actingAs($this->pm)->put(route('projects.technical-meeting.save', $project), $schedule)->assertSessionHasNoErrors();

    expect(TechnicalMeeting::where('project_id', $project->id)->value('meeting_date')->toDateString())->toBe('2026-10-20')
        ->and(SupervisorFee::where('project_id', $project->id)->exists())->toBeFalse()
        ->and(notified($this->qa, 'technical_meeting_scheduled'))->toBe(1)
        ->and(notified($this->logistics, 'technical_meeting_scheduled'))->toBe(1)
        ->and(notified($this->pm, 'technical_meeting_scheduled'))->toBe(0);

    // Same date again → no repeat; a new date → "Jadwal TM Diubah".
    $this->actingAs($this->pm)->put(route('projects.technical-meeting.save', $project), [...$schedule, 'fee_total' => 5_000_000])->assertSessionHasNoErrors();
    expect(notified($this->qa, 'technical_meeting_scheduled'))->toBe(1);

    $this->actingAs($this->pm)->put(route('projects.technical-meeting.save', $project), [...$schedule, 'meeting_date' => '2026-10-21', 'fee_total' => 5_000_000])->assertSessionHasNoErrors();
    expect(notified($this->qa, 'technical_meeting_scheduled'))->toBe(2);

    // Once a fee exists it can't be emptied.
    $this->actingAs($this->pm)->put(route('projects.technical-meeting.save', $project), $schedule)->assertSessionHasErrors('fee_total');
});

test('Perlu Tindakan: the Pengawas sees the TM date and the material plan of their projects at TM', function () {
    $project = execProject($this, ProjectPhase::Tm);

    $group = fn (User $user) => collect(app(ActionInboxService::class)->for($user, true))->firstWhere('key', 'tm-supervisor');

    expect($group($this->qa)['items'][0]['subtitle'])->toBe('Jadwal TM belum ditentukan PM · Bahan belum direncanakan')
        ->and($group($this->qa)['items'][0]['href'])->toEndWith('tab=execution#tm-bahan')
        ->and($group($this->otherQa))->toBeNull();

    TechnicalMeeting::create(['project_id' => $project->id, 'meeting_date' => '2026-10-20']);
    $this->actingAs($this->qa)->post(route('projects.materials.store', $project), ['material_id' => Material::factory()->create()->id, 'source' => 'GUDANG', 'qty_planned' => 4]);

    expect($group($this->qa)['items'][0]['subtitle'])->toBe('TM Sel, 20 Okt 2026 · 1 bahan direncanakan');

    $project->update(['phase' => ProjectPhase::Persiapan->value]);
    expect($group($this->qa))->toBeNull();
});

test('the Pengawas revises their material plan until production starts; nobody else, never a request line', function () {
    $project = execProject($this, ProjectPhase::Tm);
    $this->actingAs($this->qa)->post(route('projects.materials.store', $project), ['material_id' => Material::factory()->create()->id, 'source' => 'GUDANG', 'qty_planned' => 4])->assertSessionHasNoErrors();
    $line = $project->projectMaterials()->sole();

    $this->actingAs($this->qa)->get(route('projects.show', $project))->assertInertia(fn (Assert $page) => $page
        ->where('execution.can.planMaterials', true)
        ->where('execution.can.updateMaterials', true)
        ->where('execution.can.removeMaterials', true)
        ->where('execution.meetingMaterials.0.editable', true));

    $this->actingAs($this->otherQa)->put(route('project-materials.update', $line), ['qty_planned' => 9])->assertForbidden();
    $this->actingAs($this->otherQa)->delete(route('project-materials.destroy', $line))->assertForbidden();
    $this->actingAs($this->qa)->put(route('project-materials.update', $line), ['qty_planned' => 6.5])->assertSessionHasNoErrors();
    expect((float) $line->fresh()->qty_planned)->toBe(6.5);

    // The PM edits too, but only the Pengawas / Logistics remove.
    $this->actingAs($this->pm)->put(route('project-materials.update', $line), ['qty_planned' => 7])->assertSessionHasNoErrors();
    $this->actingAs($this->pm)->delete(route('project-materials.destroy', $line))->assertForbidden();

    // A Tukang's request keeps its own flow.
    $request = $project->projectMaterials()->create(['source' => 'CUSTOM', 'custom_name' => 'Lem khusus', 'unit_id' => $line->unit_id, 'qty_planned' => 1, 'request_status' => 'MENUNGGU_PM', 'request_channel' => 'TUKANG']);
    $this->actingAs($this->qa)->delete(route('project-materials.destroy', $request))->assertForbidden();

    $this->actingAs($this->qa)->delete(route('project-materials.destroy', $line))->assertSessionHasNoErrors();
    expect($line->fresh())->toBeNull();

    // From production on, Logistics is ordering — the Pengawas no longer revises.
    $this->actingAs($this->qa)->post(route('projects.materials.store', $project), ['material_id' => Material::factory()->create()->id, 'source' => 'GUDANG', 'qty_planned' => 2]);
    $project->update(['phase' => ProjectPhase::Produksi->value]);
    $this->actingAs($this->qa)->put(route('project-materials.update', $project->projectMaterials()->whereNull('request_channel')->sole()), ['qty_planned' => 3])->assertForbidden();
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

test('Start Produksi starts the first timeline; the others stay PENDING (Sprint 28 K9)', function () {
    $project = execProject($this, ProjectPhase::Persiapan);
    $fee = SupervisorFee::create(['project_id' => $project->id, 'supervisor_id' => $this->qa->id, 'total_amount' => 2_000_000, 'dp_percent' => 10]);
    // Created out of order: the project's `order` decides which one is first.
    $second = Milestone::factory()->create(['project_id' => $project->id, 'order' => 2, 'status' => 'PENDING', 'start_date' => '2026-10-19', 'target_date' => '2026-10-24']);
    $first = Milestone::factory()->create(['project_id' => $project->id, 'order' => 1, 'status' => 'PENDING', 'start_date' => '2026-10-12', 'target_date' => '2026-10-17']);
    $third = Milestone::factory()->create(['project_id' => $project->id, 'order' => 3, 'status' => 'PENDING', 'start_date' => '2026-10-26', 'target_date' => '2026-10-31']);
    $payment = $fee->payments()->create(['project_id' => $project->id, 'supervisor_id' => $this->qa->id, 'kind' => 'DP', 'amount' => 200_000, 'status' => 'DIAJUKAN']);

    $this->actingAs($this->pm)->post(route('supervisor-payments.decide', $payment), ['decision' => 'approve'])->assertSessionHasNoErrors();

    expect($project->fresh()->phase)->toBe(ProjectPhase::Produksi)
        ->and($first->fresh()->status)->toBe(MilestoneStatus::InProgress)
        ->and($second->fresh()->status)->toBe(MilestoneStatus::Pending)
        ->and($third->fresh()->status)->toBe(MilestoneStatus::Pending);
});

test('DP conditions say what is missing — task titles only for viewers of the project\'s tasks (Sprint 27 P1a)', function () {
    $project = execProject($this, ProjectPhase::Persiapan);
    SupervisorFee::create(['project_id' => $project->id, 'supervisor_id' => $this->qa->id, 'total_amount' => 5_000_000, 'dp_percent' => 10]);
    $first = Milestone::factory()->create(['project_id' => $project->id, 'name' => 'Rangka Kabinet', 'order' => 1, 'start_date' => '2026-10-12', 'target_date' => '2026-10-24']);
    Milestone::factory()->create(['project_id' => $project->id, 'name' => 'Finishing HPL', 'order' => 2, 'start_date' => '2026-10-12', 'target_date' => '2026-10-24']);
    execTarget($project, $first, $this->tukang, ['title' => 'Pasang rangka atas', 'rate_per_task' => null]);

    $this->actingAs($this->qa)->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page
            ->where('execution.dpRequirements.0.done', false)
            ->where('execution.dpRequirements.0.detail', 'Belum ada rencana bahan.')
            ->where('execution.dpRequirements.1.detail', '1 daily target belum punya upah.')
            ->where('execution.dpRequirements.1.missing', ['Pasang rangka atas · 14/10'])
            ->where('execution.dpRequirements.2.detail', '1 timeline belum punya daily target.')
            ->where('execution.dpRequirements.2.missing', ['Finishing HPL']));

    // A QA who isn't this project's Pengawas, and Logistik: the count, never the task title (PRD §4.6).
    foreach ([$this->otherQa, $this->logistics] as $viewer) {
        $this->actingAs($viewer)->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('execution.dpRequirements.1.detail', '1 daily target belum punya upah.')
                ->where('execution.dpRequirements.1.missing', [])
                ->where('execution.dpRequirements.2.missing', ['Finishing HPL']));
    }

    // The rule itself is unchanged: still refused until every condition is met.
    $this->actingAs($this->qa)->post(route('projects.supervisor-payments.dp', $project))->assertSessionHasErrors('dp');
});

test('daftar proyek: a Pengawas starts on its own projects, every role can filter by phase (Sprint 27 P6a)', function () {
    $mineAtTm = execProject($this, ProjectPhase::Tm);
    $mineProducing = execProject($this, ProjectPhase::Produksi);
    $theirs = execProject($this, ProjectPhase::Produksi, ['supervisor_id' => $this->otherQa->id]);
    $done = execProject($this, ProjectPhase::Pemasangan, ['status' => ProjectStatus::Completed->value]);

    // QA/QC: "Yang saya awasi" by default, `supervised=0` shows every project (access unchanged).
    $this->actingAs($this->qa)->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page->where('filters.supervised', '1')->where('canFilterSupervised', true)
            ->where('projects.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect([$mineAtTm->id, $mineProducing->id, $done->id])->sort()->values()->all()));
    $this->actingAs($this->qa)->get(route('projects.index', ['supervised' => '0']))
        ->assertInertia(fn (Assert $page) => $page->has('projects.data', 4));

    // Tahap: a running project's phase (ACTIVE only); SELESAI = completed.
    $this->actingAs($this->pm)->get(route('projects.index', ['phase' => 'PRODUKSI']))
        ->assertInertia(fn (Assert $page) => $page->where('canFilterSupervised', false)->missing('filters.supervised')
            ->where('projects.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect([$mineProducing->id, $theirs->id])->sort()->values()->all()));
    $this->actingAs($this->pm)->get(route('projects.index', ['phase' => 'SELESAI']))
        ->assertInertia(fn (Assert $page) => $page->has('projects.data', 1)->where('projects.data.0.id', $done->id));
    $this->actingAs($this->pm)->get(route('projects.index', ['phase' => 'BUKAN_FASE']))
        ->assertInertia(fn (Assert $page) => $page->has('projects.data', 4));
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

test('geser: the Pengawas moves a target to any working day; the Tukang never', function () {
    $project = execProject($this, ProjectPhase::Produksi);
    $task = execTarget($project, execTimeline($project), $this->tukang);

    $this->actingAs($this->tukang)->patch(route('tasks.reschedule', $task), ['due_date' => '2026-10-16'])->assertForbidden();
    $this->actingAs($this->otherQa)->patch(route('tasks.reschedule', $task), ['due_date' => '2026-10-16'])->assertForbidden();
    $this->actingAs($this->qa)->patch(route('tasks.reschedule', $task), ['due_date' => '2026-10-16', 'note' => 'Bahan telat'])->assertSessionHasNoErrors();

    expect($task->fresh()->due_date->toDateString())->toBe('2026-10-16')
        ->and($task->fresh()->planned_date->toDateString())->toBe('2026-10-14')
        ->and(TaskScheduleChange::where('task_id', $task->id)->where('kind', 'GESER')->value('note'))->toBe('Bahan telat')
        ->and(notified($this->tukang, 'task_updated'))->toBe(1);
});

test('geser outside its timeline follows the timeline of the new day; the history says so (Sprint 29 Sub 05, Q29)', function () {
    $project = execProject($this, ProjectPhase::Produksi);
    $first = execTimeline($project, '2026-10-12', '2026-10-17');
    $second = execTimeline($project, '2026-10-19', '2026-10-31');
    $first->update(['name' => 'Rangka', 'order' => 1]);
    $second->update(['name' => 'Finishing', 'order' => 2]);
    $task = execTarget($project, $first, $this->tukang);

    $this->actingAs($this->qa)->patch(route('tasks.reschedule', $task), ['due_date' => '2026-10-21'])
        ->assertSessionHasNoErrors()->assertSessionHas('success', 'Tanggal target digeser — dipindah ke timeline Finishing.');
    expect($task->fresh()->milestone_id)->toBe($second->id)
        ->and(TaskScheduleChange::where('task_id', $task->id)->sole()->note)->toBe('Pindah timeline: Rangka → Finishing');

    // A swap across the two timelines moves each target into the other one.
    $other = execTarget($project, $first, $this->tukang, ['due_date' => '2026-10-15']);
    $this->actingAs($this->qa)->post(route('projects.targets.swap', $project), ['from_date' => '2026-10-15', 'to_date' => '2026-10-21'])->assertSessionHasNoErrors();
    expect($other->fresh()->milestone_id)->toBe($second->id)
        ->and($task->fresh()->milestone_id)->toBe($first->id)
        ->and($task->fresh()->due_date->toDateString())->toBe('2026-10-15');

    // The midnight roll-over now bounds it by its new timeline.
    expect($other->fresh()->milestone->target_date->toDateString())->toBe('2026-10-31');
});

test('geser onto a Sunday is refused — not a working day (Sprint 28 K13)', function () {
    $project = execProject($this, ProjectPhase::Produksi);
    $task = execTarget($project, execTimeline($project), $this->tukang);

    // 2026-10-18 is a Sunday inside the timeline (12 → 24 Oct).
    $this->actingAs($this->qa)->patch(route('tasks.reschedule', $task), ['due_date' => '2026-10-18'])
        ->assertSessionHasErrors(['due_date' => 'Hari Minggu bukan hari kerja — pilih Senin–Sabtu.']);

    expect($task->fresh()->due_date->toDateString())->toBe('2026-10-14')
        ->and(TaskScheduleChange::where('task_id', $task->id)->exists())->toBeFalse();
});

test('tukar: two days of targets swap, also across timelines (Sprint 29 Sub 05)', function () {
    $project = execProject($this, ProjectPhase::Produksi);
    $milestone = execTimeline($project, '2026-10-12', '2026-10-20');
    $a = execTarget($project, $milestone, $this->tukang, ['due_date' => '2026-10-15']);
    $b = execTarget($project, $milestone, $this->tukang, ['due_date' => '2026-10-16']);

    $this->actingAs($this->qa)->post(route('projects.targets.swap', $project), ['from_date' => '2026-10-15', 'to_date' => '2026-10-16'])->assertSessionHasNoErrors();
    expect($a->fresh()->due_date->toDateString())->toBe('2026-10-16')
        ->and($b->fresh()->due_date->toDateString())->toBe('2026-10-15');

    // Fri 23 is past the timeline (12 → 20): accepted now; no timeline covers it, so it keeps its own.
    $this->actingAs($this->qa)->post(route('projects.targets.swap', $project), ['from_date' => '2026-10-15', 'to_date' => '2026-10-23'])->assertSessionHasNoErrors();
    expect($b->fresh()->due_date->toDateString())->toBe('2026-10-23')
        ->and($b->fresh()->milestone_id)->toBe($milestone->id);

    $this->actingAs($this->marketing)->post(route('projects.targets.swap', $project), ['from_date' => '2026-10-15', 'to_date' => '2026-10-16'])->assertForbidden();
});

test('tukar is refused as a whole when a target would land on a Sunday (Sprint 28 K13)', function () {
    $project = execProject($this, ProjectPhase::Produksi);
    $milestone = execTimeline($project, '2026-10-12', '2026-10-24');
    $a = execTarget($project, $milestone, $this->tukang, ['due_date' => '2026-10-16']);
    $b = execTarget($project, $milestone, $this->tukang, ['due_date' => '2026-10-17']);

    // Friday's target would go to Sunday 18 — whichever field holds the Sunday.
    $this->actingAs($this->qa)->post(route('projects.targets.swap', $project), ['from_date' => '2026-10-16', 'to_date' => '2026-10-18'])
        ->assertSessionHasErrors(['to_date' => "Target \"{$a->title}\" akan pindah ke hari Minggu — bukan hari kerja, tukar dibatalkan."]);
    $this->actingAs($this->qa)->post(route('projects.targets.swap', $project), ['from_date' => '2026-10-18', 'to_date' => '2026-10-17'])
        ->assertSessionHasErrors('from_date');

    expect($a->fresh()->due_date->toDateString())->toBe('2026-10-16')
        ->and($b->fresh()->due_date->toDateString())->toBe('2026-10-17')
        ->and(TaskScheduleChange::count())->toBe(0);
});

test('tukar may move a target left on a Sunday off it (Sprint 28 K13, loosened)', function () {
    $project = execProject($this, ProjectPhase::Produksi);
    $milestone = execTimeline($project, '2026-10-12', '2026-10-24');
    $sunday = execTarget($project, $milestone, $this->tukang, ['due_date' => '2026-10-18']);

    // Monday 19 is empty: only the Sunday target moves, onto a working day.
    $this->actingAs($this->qa)->post(route('projects.targets.swap', $project), ['from_date' => '2026-10-18', 'to_date' => '2026-10-19'])
        ->assertSessionHasNoErrors();

    expect($sunday->fresh()->due_date->toDateString())->toBe('2026-10-19')
        ->and(TaskScheduleChange::count())->toBe(1);
});

// ── Sub 08: Laporan mingguan ─────────────────────────────────────────────

test('the weekly report may be sent before Friday; the Pengawas is queued from Thursday until it is sent (Sprint 29 Sub 02)', function () {
    $project = execProject($this, ProjectPhase::Produksi, ['production_started_at' => '2026-10-05 08:00:00']);
    $other = execProject($this, ProjectPhase::Produksi, ['production_started_at' => '2026-10-05 08:00:00']);
    $queue = fn () => collect(app(ActionInboxService::class)->for($this->qa, fresh: true))->firstWhere('key', 'weekly-report-due');

    // Tuesday: accepted, and no queue yet.
    Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'Asia/Jakarta'));
    $this->actingAs($this->qa)->post(route('projects.weekly-reports.store', $project), ['notes' => 'Rangka partisi terpasang'])->assertSessionHasNoErrors();
    expect(WeeklyReport::where('project_id', $project->id)->sole()->week_number)->toBe(2)
        ->and($queue())->toBeNull();
    $this->actingAs($this->qa)->get(route('projects.show', ['project' => $other, 'tab' => 'execution']))
        ->assertInertia(fn (Assert $page) => $page->where('execution.currentWeek.is_due', false));

    // Thursday: only the project without a report this week, once.
    Carbon::setTestNow(Carbon::parse('2026-10-15 09:00:00', 'Asia/Jakarta'));
    $group = $queue();
    expect($group['count'])->toBe(1)
        ->and($group['items'][0]['title'])->toBe($other->name)
        ->and($group['items'][0]['subtitle'])->toBe('Minggu ke-2');

    // Friday it is due; sending it empties the queue.
    Carbon::setTestNow(Carbon::parse('2026-10-16 09:00:00', 'Asia/Jakarta'));
    $this->actingAs($this->qa)->get(route('projects.show', ['project' => $other, 'tab' => 'execution']))
        ->assertInertia(fn (Assert $page) => $page->where('execution.currentWeek.is_due', true));
    $this->actingAs($this->qa)->post(route('projects.weekly-reports.store', $other), ['notes' => 'Mural 50%'])->assertSessionHasNoErrors();
    expect($queue())->toBeNull();

    // Another Pengawas never sees it.
    expect(collect(app(ActionInboxService::class)->for($this->otherQa, fresh: true))->pluck('key'))->not->toContain('weekly-report-due');
});

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

// ── Sprint 29 Sub 04: approval & centang Pengawas ───────────────────────

test('the Tukang "Selesai" waits for the Pengawas, who approves it — completed when the Tukang marked it', function () {
    $project = execProject($this, ProjectPhase::Produksi);
    $task = execTarget($project, execTimeline($project), $this->tukang);

    Carbon::setTestNow(Carbon::parse('2026-10-14 15:30:00', 'Asia/Jakarta'));
    $this->actingAs($this->tukang)->patch(route('tasks.updateStatus', $task), ['status' => 'DONE'])->assertSessionHasNoErrors();
    expect($task->fresh()->status)->toBe(TaskStatus::Pengecekan)
        ->and(DailyTaskForm::where('task_id', $task->id)->sole()->status_update)->toBe(TaskStatus::Pengecekan);

    // Only whoever plans the targets of this project decides.
    $this->actingAs($this->otherQa)->post(route('tasks.approve', $task))->assertForbidden();
    $this->actingAs($this->tukang)->post(route('tasks.approve', $task))->assertForbidden();

    Carbon::setTestNow(Carbon::parse('2026-10-14 18:00:00', 'Asia/Jakarta'));
    $this->actingAs($this->qa)->post(route('tasks.approve', $task))->assertSessionHasNoErrors();
    expect($task->fresh()->status)->toBe(TaskStatus::Done)
        ->and($task->fresh()->completed_at->timezone('Asia/Jakarta')->format('H:i'))->toBe('15:30')
        ->and(AuditLog::where('action', 'task.approved')->count())->toBe(1);

    $this->actingAs($this->qa)->post(route('tasks.approve', $task))->assertSessionHasErrors('status');
});

test('"Kembalikan" needs a reason, puts the target back to Dikerjakan and tells the Tukang', function () {
    $project = execProject($this, ProjectPhase::Produksi);
    $task = execTarget($project, execTimeline($project), $this->tukang, ['status' => TaskStatus::Pengecekan->value]);

    $this->actingAs($this->otherQa)->post(route('tasks.return', $task), ['note' => 'Belum rapi'])->assertForbidden();
    $this->actingAs($this->qa)->post(route('tasks.return', $task), ['note' => ''])->assertSessionHasErrors('note');
    $this->actingAs($this->qa)->post(route('tasks.return', $task), ['note' => 'Sambungan HPL belum rapi'])->assertSessionHasNoErrors();

    expect($task->fresh()->status)->toBe(TaskStatus::OnProgress)
        ->and(Notification::where('user_id', $this->tukang->id)->where('title', 'Target Dikembalikan')->value('message'))->toContain('Sambungan HPL belum rapi')
        ->and(AuditLog::where('action', 'task.returned')->sole()->new_values['note'])->toBe('Sambungan HPL belum rapi');
});

test('the Pengawas ticks an untouched target done for the Tukang — counted as the day report, no penalty (F3, Q30)', function () {
    $project = execProject($this, ProjectPhase::Produksi);
    $task = execTarget($project, execTimeline($project), $this->tukang);

    $this->actingAs($this->otherQa)->post(route('tasks.checkOff', $task))->assertForbidden();
    $this->actingAs($this->qa)->post(route('tasks.checkOff', $task))->assertSessionHasNoErrors();

    $form = DailyTaskForm::where('task_id', $task->id)->sole();
    expect($task->fresh()->status)->toBe(TaskStatus::Done)
        ->and($form->staff_id)->toBe($this->tukang->id)
        ->and($form->work_date->toDateString())->toBe('2026-10-14')
        ->and(Task::awaitingDailyForm($this->tukang, '2026-10-14')->count())->toBe(0)
        ->and(AuditLog::where('action', 'task.checked_by_supervisor')->sole()->user_id)->toBe($this->qa->id);
});

test('"Daily target menunggu dicek" queues for the project Pengawas; "Setujui semua" empties it', function () {
    $project = execProject($this, ProjectPhase::Produksi);
    $timeline = execTimeline($project);
    $a = execTarget($project, $timeline, $this->tukang, ['status' => TaskStatus::Pengecekan->value]);
    $b = execTarget($project, $timeline, $this->tukang, ['status' => TaskStatus::Pengecekan->value]);
    $queue = fn (User $user) => collect(app(ActionInboxService::class)->for($user, fresh: true))->firstWhere('key', 'targets-to-check');

    expect($queue($this->qa)['items'][0]['subtitle'])->toBe('2 target menunggu dicek')
        ->and($queue($this->otherQa))->toBeNull();

    // The Pengawas's Tugas menu lists them (its projects only).
    $this->actingAs($this->qa)->get(route('tasks.index', ['status' => 'PENGECEKAN']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('tasks.total', 2));
    $this->actingAs($this->otherQa)->get(route('tasks.index'))->assertInertia(fn (Assert $page) => $page->where('tasks.total', 0));

    $this->actingAs($this->qa)->post(route('tasks.approveMany', $project), ['task_ids' => [$a->id, $b->id]])
        ->assertSessionHas('success', '2 target disetujui selesai.');
    expect($queue($this->qa))->toBeNull();
});

test('one target for several tukang × the working days of a range, refused as a whole outside the timeline (K5)', function () {
    $project = execProject($this, ProjectPhase::Produksi);
    $timeline = execTimeline($project, '2026-10-12', '2026-10-24');
    $second = User::factory()->create(['is_active' => true]);
    $second->assignRole('FIELD_STAFF');
    $payload = ['milestone_id' => $timeline->id, 'title' => 'Pasang rangka plafon', 'assignee_ids' => [$this->tukang->id, $second->id], 'rate_per_task' => 150_000];

    // Thu 15 → Mon 19: Thu, Fri, Sat, Mon (Sunday skipped) × 2 tukang.
    $this->actingAs($this->qa)->post(route('tasks.storeMany', $project), [...$payload, 'date_from' => '2026-10-15', 'date_to' => '2026-10-19'])
        ->assertSessionHasNoErrors()->assertSessionHas('success', '8 daily target berhasil ditambahkan.');
    expect(Task::where('title', 'Pasang rangka plafon')->whereDate('due_date', '2026-10-18')->exists())->toBeFalse()
        ->and(Task::where('title', 'Pasang rangka plafon')->where('assignee_id', $second->id)->count())->toBe(4);

    $this->actingAs($this->qa)->post(route('tasks.storeMany', $project), [...$payload, 'title' => 'Lewat', 'date_from' => '2026-10-23', 'date_to' => '2026-10-27'])
        ->assertSessionHasErrors();
    $this->actingAs($this->otherQa)->post(route('tasks.storeMany', $project), [...$payload, 'date_from' => '2026-10-15', 'date_to' => '2026-10-15'])->assertForbidden();
    expect(Task::where('title', 'Lewat')->exists())->toBeFalse();
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
    // Sprint 29 Sub 04 — the Pengawas approves what the Tukang reported done.
    $this->actingAs($this->qa)->post(route('tasks.approveMany', $project), ['task_ids' => [$tasks[6]->id, $tasks[7]->id]])->assertSessionHasNoErrors();

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
    $this->actingAs($this->qa)->post(route('tasks.approve', $repair))->assertSessionHasNoErrors();
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
        'contract_value' => $project->contract_value, 'status' => 'ACTIVE', 'supervisor_ids' => [$this->otherQa->id],
    ], $this->pm);

    expect($project->supervisors()->pluck('users.id')->all())->toBe([$this->otherQa->id])
        ->and(notified($this->otherQa, 'project_supervisor_assigned'))->toBe(1)
        ->and(notified($this->qa, 'project_supervisor_unassigned'))->toBe(1);
});

test('two Pengawas are equal — targets, QA, reports, materials; another QA is refused; both are told (Sprint 29 Sub 06)', function () {
    $project = execProject($this, ProjectPhase::Produksi);
    $second = User::factory()->create(['is_active' => true]);
    $second->assignRole('QA');
    $outsider = User::factory()->create(['is_active' => true]);
    $outsider->assignRole('QA');

    // Ubah Proyek: + a second Pengawas — only the new one is told.
    app(ProjectService::class)->update($project, [
        'name' => $project->name, 'start_date' => '2026-10-12', 'end_date' => '2026-10-31',
        'contract_value' => $project->contract_value, 'status' => 'ACTIVE', 'supervisor_ids' => [$this->qa->id, $second->id],
    ], $this->pm);
    expect($project->supervisors()->pluck('users.id')->all())->toBe([$this->qa->id, $second->id])
        ->and(notified($second, 'project_supervisor_assigned'))->toBe(1)
        ->and(notified($this->qa, 'project_supervisor_assigned'))->toBe(0);

    $timeline = execTimeline($project);
    $task = execTarget($project, $timeline, $this->tukang, ['status' => TaskStatus::Pengecekan->value]);

    // The second Pengawas may do what the first does; another QA may not.
    $this->actingAs($outsider)->post(route('tasks.approve', $task))->assertForbidden();
    $this->actingAs($second)->post(route('tasks.approve', $task))->assertSessionHasNoErrors();
    $this->actingAs($second)->post(route('tasks.store', $project), [
        'milestone_id' => $timeline->id, 'title' => 'Pasang list', 'assignee_id' => $this->tukang->id, 'due_date' => '2026-10-15',
    ])->assertSessionHasNoErrors();
    $this->actingAs($second)->post(route('projects.weekly-reports.store', $project), ['notes' => 'Rangka terpasang'])->assertSessionHasNoErrors();
    $this->actingAs($this->qa)->post(route('projects.weekly-reports.store', $project), ['notes' => 'Lagi'])->assertSessionHasErrors('notes');

    expect($project->isSupervisedBy($second))->toBeTrue()
        ->and($project->isSupervisedBy($outsider))->toBeFalse()
        ->and(Project::supervisedBy($second)->pluck('id')->all())->toBe([$project->id])
        ->and(WeeklyReport::where('project_id', $project->id)->sole()->supervisor_id)->toBe($second->id);

    // The Detail Proyek lists both names.
    $this->actingAs($this->pm)->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page->where('project.supervisors', fn ($rows) => collect($rows)->pluck('id')->all() === [$this->qa->id, $second->id]));

    // Removing one tells them; the other stays.
    app(ProjectService::class)->update($project->fresh(), [
        'name' => $project->name, 'start_date' => '2026-10-12', 'end_date' => '2026-10-31',
        'contract_value' => $project->contract_value, 'status' => 'ACTIVE', 'supervisor_ids' => [$second->id],
    ], $this->pm);
    expect(notified($this->qa, 'project_supervisor_unassigned'))->toBe(1)
        ->and($project->fresh()->isSupervisedBy($this->qa))->toBeFalse();
});

test('the supervisor_id → project_supervisors migration keeps the Pengawas and rolls back', function () {
    $project = execProject($this, ProjectPhase::Produksi);
    $migration = require database_path('migrations/2026_10_21_080822_create_project_supervisors_table.php');

    $migration->down();
    expect(DB::table('projects')->where('id', $project->id)->value('supervisor_id'))->toBe($this->qa->id);

    $migration->up();
    expect($project->supervisors()->pluck('users.id')->all())->toBe([$this->qa->id])
        ->and(Schema::hasColumn('projects', 'supervisor_id'))->toBeFalse();
});

// ── Sprint 29 Sub 08: Upah Tukang per item RAB (File A) ─────────────────

/** File A "Dapur Bersih": [uraian, volume, harga RAB, satuan upah|null]. */
function fileAProject(object $test, ProjectPhase $phase = ProjectPhase::Persiapan): Project
{
    $quotation = Quotation::factory()->create(['type' => 'PROYEK']);
    foreach ([
        ['Kabinet bawah', 3.16, 3_300_000],
        ['Kabinet atas kanan', 2.45, 2_850_000],
        ['Kabinet atas kiri', 1.75, 2_850_000],
        ['Cover kulkas', 4.25, 2_850_000],
        ['Cover atas HPL', 4.4, 1_800_000],
        ['Meja bar', 1.3, 3_800_000],
        ['Rak piring, LED, power supply, saklar', 1, 2_000_000],
    ] as $i => [$description, $qty, $price]) {
        QuotationItem::factory()->create([
            'quotation_id' => $quotation->id, 'description' => $description, 'qty' => $qty,
            'unit_price' => $price, 'total_price' => round($qty * $price, 2), 'sort_order' => $i,
        ]);
    }

    $project = execProject($test, $phase, ['quotation_id' => $quotation->id, 'team_lead_name' => 'Putra']);
    app(WorkItemService::class)->ensureForProject($project);

    return $project;
}

function wageItem(Project $project, string $description): ProjectWorkItem
{
    return $project->workItems()->whereHas('quotationItem', fn ($q) => $q->where('description', $description))->sole();
}

test('Upah Tukang per item: satuan upah × volume, total Rp 7.705.000, bobot from the RAB value (File A)', function () {
    $project = fileAProject($this);
    $wages = ['Kabinet bawah' => 500_000, 'Kabinet atas kanan' => 500_000, 'Kabinet atas kiri' => 500_000, 'Cover kulkas' => 300_000, 'Cover atas HPL' => 300_000, 'Meja bar' => 1_100_000];

    foreach ($wages as $description => $wage) {
        $this->actingAs($this->qa)->put(route('projects.work-items.update', wageItem($project, $description)), ['unit_wage' => $wage])->assertSessionHasNoErrors();
    }

    $overview = app(WorkItemService::class)->overview($project);
    $rows = collect($overview['sections'])->flatMap(fn ($section) => $section['items'])->keyBy('description');

    expect($rows['Kabinet bawah']['wage_subtotal'])->toBe(1_580_000.0)
        ->and($rows['Cover kulkas']['wage_subtotal'])->toBe(1_275_000.0)
        ->and($rows['Meja bar']['wage_subtotal'])->toBe(1_430_000.0)
        ->and($rows['Rak piring, LED, power supply, saklar']['wage_subtotal'])->toBe(0.0)
        ->and($rows['Rak piring, LED, power supply, saklar']['weight'])->toBeNull()
        // 10.428.000 ÷ 47.370.500 (RAB of the items with a wage) × 100.
        ->and($rows['Kabinet bawah']['weight'])->toBe(22.01)
        ->and($rows['Kabinet bawah']['team_lead'])->toBe('Putra')
        ->and($overview['summary']['total_wage'])->toBe(7_705_000.0)
        ->and($overview['summary']['unpaid_items'])->toBe(1)
        ->and($overview['summary']['per_team_lead'][0])->toMatchArray(['label' => 'Putra', 'total' => 7_705_000.0])
        // The Sprint 24 payment flow reads the same total; DP 10 % by default.
        ->and((float) SupervisorFee::where('project_id', $project->id)->sole()->total_amount)->toBe(7_705_000.0)
        ->and(SupervisorFee::where('project_id', $project->id)->sole()->dpAmount())->toBe(770_500.0);

    // Syarat DP: "upah ✓" from the items.
    $this->actingAs($this->qa)->get(route('projects.show', $project))->assertInertia(fn (Assert $page) => $page
        ->where('execution.dpRequirements', fn ($rows) => collect($rows)->firstWhere('key', 'wages')['done'] === true
            && ! collect($rows)->contains('key', 'fee'))
        ->where('execution.workItems.summary.total_wage', 7_705_000)
        ->where('usesWorkItems', true));
});

test('Upah Tukang: the Pengawas and the Admin Proyek fill it; the PM, Marketing and another QA cannot; Logistik never gets RAB prices', function () {
    $project = fileAProject($this);
    $item = wageItem($project, 'Meja bar');

    $this->actingAs($this->pm)->put(route('projects.work-items.update', $item), ['unit_wage' => 1])->assertForbidden();
    $this->actingAs($this->marketing)->put(route('projects.work-items.update', $item), ['unit_wage' => 1])->assertForbidden();
    $this->actingAs($this->otherQa)->put(route('projects.work-items.update', $item), ['unit_wage' => 1])->assertForbidden();
    $this->actingAs(execUser('ADMIN_PROYEK'))->put(route('projects.work-items.update', $item), ['unit_wage' => 1_100_000, 'team_lead_name' => 'Evan'])
        ->assertSessionHasNoErrors();
    expect((float) $item->fresh()->unit_wage)->toBe(1_100_000.0)->and($item->fresh()->team_lead_name)->toBe('Evan');

    // "Terapkan ke bagian ini".
    $this->actingAs($this->qa)->post(route('projects.work-items.teamLead', $project), ['item_ids' => $project->workItems()->pluck('id')->all(), 'team_lead_name' => 'Yusup'])
        ->assertSessionHas('success', 'Kepala Tim diterapkan ke 7 item.');

    $this->actingAs($this->pm)->get(route('projects.show', $project))->assertInertia(fn (Assert $page) => $page->has('execution.workItems.sections'));
    $this->actingAs($this->logistics)->get(route('projects.show', $project))->assertInertia(fn (Assert $page) => $page->where('execution.workItems', null));
});

test('after Start Produksi a set wage is locked; an item without a wage (RAB Tambahan) may still get one', function () {
    $project = fileAProject($this, ProjectPhase::Produksi);
    $this->actingAs($this->qa)->put(route('projects.work-items.update', wageItem($project, 'Kabinet bawah')), ['unit_wage' => 500_000])->assertSessionHasNoErrors();

    $this->actingAs($this->qa)->put(route('projects.work-items.update', wageItem($project, 'Kabinet bawah')), ['unit_wage' => 600_000])
        ->assertSessionHasErrors(['unit_wage' => 'Upah item ini dikunci setelah Start Produksi.']);
    $this->actingAs($this->qa)->put(route('projects.work-items.update', wageItem($project, 'Meja bar')), ['unit_wage' => 1_100_000])->assertSessionHasNoErrors();

    expect(app(WorkItemService::class)->totalWage($project))->toBe(1_580_000.0 + 1_430_000.0);
});

// ── Sprint 29 Sub 07: Admin Proyek ───────────────────────────────────────

test('the Admin Proyek inputs on any project: TM, timeline, bahan, targets, status, temuan, catatan (H2)', function () {
    $admin = execUser('ADMIN_PROYEK');
    $tm = execProject($this, ProjectPhase::Tm, ['end_date' => null, 'pm_id' => $this->otherPm->id]);

    $this->actingAs($admin)->put(route('projects.technical-meeting.save', $tm), [
        'meeting_date' => '2026-10-14', 'notes' => 'Diketik admin', 'attendees' => [['user_id' => $this->qa->id]],
        'fee_total' => '', 'dp_percent' => 10, 'end_date' => '2026-10-31',
    ])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('milestones.store', $tm), ['name' => 'Rangka', 'start_date' => '2026-10-12', 'target_date' => '2026-10-24'])
        ->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('projects.materials.store', $tm), ['material_id' => Material::factory()->create()->id, 'source' => 'GUDANG', 'qty_planned' => 3])
        ->assertSessionHasNoErrors();

    $project = execProject($this, ProjectPhase::Produksi);
    $timeline = execTimeline($project);
    $this->actingAs($admin)->post(route('tasks.store', $project), [
        'milestone_id' => $timeline->id, 'title' => 'Pasang rangka', 'assignee_id' => $this->tukang->id, 'due_date' => '2026-10-15', 'rate_per_task' => 150_000,
    ])->assertSessionHasNoErrors();
    $task = Task::where('title', 'Pasang rangka')->sole();
    $this->actingAs($admin)->patch(route('tasks.reschedule', $task), ['due_date' => '2026-10-16'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('tasks.checkOff', $task))->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('progress-logs.store', $project), ['description' => 'Rangka 50%'])->assertSessionHasNoErrors();

    $site = execProject($this, ProjectPhase::Pemasangan);
    $this->actingAs($admin)->post(route('projects.site-findings.store', $site), ['description' => 'Sambungan HPL renggang'])->assertSessionHasNoErrors();

    expect($task->fresh()->status)->toBe(TaskStatus::Done)
        ->and(AuditLog::where('action', 'task.checked_by_supervisor')->sole()->user_id)->toBe($admin->id);
});

test('the Admin Proyek never decides: TM Selesai, DP, Berangkat, Tandai Selesai, QA, budget, Buka Proyek (H3)', function () {
    $admin = execUser('ADMIN_PROYEK');
    $tm = execProject($this, ProjectPhase::Tm);
    $project = execProject($this, ProjectPhase::Produksi);
    $timeline = execTimeline($project);
    $fee = SupervisorFee::create(['project_id' => $project->id, 'supervisor_id' => $this->qa->id, 'total_amount' => 2_000_000, 'dp_percent' => 10]);
    $payment = $fee->payments()->create(['project_id' => $project->id, 'supervisor_id' => $this->qa->id, 'kind' => 'DP', 'amount' => 200_000, 'status' => 'DIAJUKAN']);

    $this->actingAs($admin)->post(route('projects.technical-meeting.complete', $tm))->assertForbidden();
    $this->actingAs($admin)->post(route('supervisor-payments.decide', $payment), ['decision' => 'approve'])->assertForbidden();
    $this->actingAs($admin)->post(route('projects.supervisor-payments.dp', $project))->assertForbidden();
    $this->actingAs($admin)->post(route('projects.departure.approve', $project))->assertForbidden();
    $this->actingAs($admin)->post(route('milestones.markDone', $timeline))->assertForbidden();
    $this->actingAs($admin)->post(route('projects.weekly-reports.store', $project), ['notes' => 'x'])->assertForbidden();
    $this->actingAs($admin)->post(route('projects.budget.posts.store', $project), ['name' => 'Interior'])->assertForbidden();

    // Detail Proyek: the work, never the client's money nor the PM's budget.
    $this->actingAs($admin)->get(route('projects.show', $project))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('canViewTasks', true)
        ->where('canManageTasks', true)
        ->where('termins', [])
        ->where('documents', null)
        ->where('budget', null)
        ->where('canViewFinanceSummary', false)
        ->where('execution.can.completeMeeting', false));
    $this->actingAs($admin)->get(route('projects.show', $tm))->assertInertia(fn (Assert $page) => $page
        ->where('execution.can.saveMeeting', true)
        ->where('execution.can.completeMeeting', false));
});

test('the Admin Proyek lands on Perlu Tindakan with the projects waiting for input', function () {
    $admin = execUser('ADMIN_PROYEK');
    $tm = execProject($this, ProjectPhase::Tm);
    execProject($this, ProjectPhase::Produksi);

    $group = collect(app(ActionInboxService::class)->for($admin, fresh: true))->firstWhere('key', 'admin-input');
    expect(collect($group['items'])->pluck('title')->all())->toBe([$tm->name])
        ->and(app(RoleRedirectService::class)->routeNameFor($admin))->toBe('inbox.index');
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
