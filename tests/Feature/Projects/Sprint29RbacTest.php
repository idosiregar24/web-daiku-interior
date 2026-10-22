<?php

use App\Enums\ProjectPhase;
use App\Enums\TaskStatus;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectWorkItem;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\SupervisorPayment;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkItemQaCheck;
use App\Services\WorkItemService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/*
 * Sprint 29 Sub 15 — one RBAC matrix over the routes Sprint 29 added or
 * opened to new roles (security-standards §4: every route, the roles that
 * may not → 403). The allowed side of each route is covered by its sub's own
 * tests (ExecutionFlowTest, WorkItemQaTest, SupervisionJourneyTest, …).
 * "QA_LAIN" = a QA user who is not this project's Pengawas.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', 'Asia/Jakarta'));
    Storage::fake('local');
    $this->seed(RoleSeeder::class);

    $this->users = collect(['PM', 'QA', 'QA_LAIN', 'ADMIN_PROYEK', 'LOGISTICS', 'FINANCE', 'HR', 'FIELD_STAFF', 'MARKETING'])
        ->mapWithKeys(function (string $key) {
            $user = User::factory()->create();
            $user->assignRole($key === 'QA_LAIN' ? 'QA' : $key);

            return [$key => $user];
        });

    $quotation = Quotation::factory()->create(['type' => 'PROYEK']);
    QuotationItem::factory()->create(['quotation_id' => $quotation->id, 'description' => 'Kitchen set', 'qty' => 1, 'unit_price' => 10_000_000, 'total_price' => 10_000_000]);
    $this->project = Project::factory()->create([
        'pm_id' => $this->users['PM']->id, 'quotation_id' => $quotation->id, 'phase' => ProjectPhase::Produksi->value,
        'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
    ]);
    $this->project->supervisors()->attach($this->users['QA']->id);
    app(WorkItemService::class)->ensureForProject($this->project);
    $this->item = ProjectWorkItem::sole();
    // Through the service, so the Upah Tukang fee row exists.
    app(WorkItemService::class)->update($this->item, ['unit_wage' => 100_000], $this->users['QA']);

    $milestone = Milestone::factory()->create(['project_id' => $this->project->id, 'start_date' => '2026-10-01', 'target_date' => '2026-10-31']);
    $this->task = Task::factory()->create([
        'project_id' => $this->project->id, 'milestone_id' => $milestone->id, 'assignee_id' => $this->users['FIELD_STAFF']->id,
        'status' => TaskStatus::Pengecekan->value, 'due_date' => '2026-10-14', 'planned_date' => '2026-10-14',
    ]);
    $this->payment = SupervisorPayment::create([
        'project_id' => $this->project->id, 'supervisor_fee_id' => $this->project->supervisorFee()->value('id'),
        'supervisor_id' => $this->users['QA']->id, 'kind' => 'MINGGUAN', 'amount' => 50_000, 'status' => 'DIAJUKAN',
    ]);
    $this->check = WorkItemQaCheck::create([
        'project_id' => $this->project->id, 'project_work_item_id' => $this->item->id, 'decision' => 'LOLOS',
        'photo_path' => 'work-item-qa/x.webp', 'checked_by' => $this->users['QA']->id,
    ]);
    Storage::disk('local')->put('work-item-qa/x.webp', 'x');
});

afterEach(fn () => Carbon::setTestNow());

/** [method, url, payload] of each route. */
function sprint29Route(object $test, string $name): array
{
    return match ($name) {
        'work-items.update' => ['put', route('projects.work-items.update', $test->item), ['unit_wage' => 120_000]],
        'work-items.teamLead' => ['post', route('projects.work-items.teamLead', $test->project), ['item_ids' => [$test->item->id], 'team_lead_name' => 'Putra']],
        'work-check.draft' => ['put', route('projects.work-check.draft', $test->project), ['progress' => [['item_id' => $test->item->id, 'percent' => 10]]]],
        'weekly-reports.store' => ['post', route('projects.weekly-reports.store', $test->project), ['notes' => 'Pengecekan minggu ini.', 'progress' => [['item_id' => $test->item->id, 'percent' => 10]]]],
        'supervisor-payments.decide' => ['post', route('supervisor-payments.decide', $test->payment), ['decision' => 'approve']],
        'tasks.approve' => ['post', route('tasks.approve', $test->task), []],
        'tasks.return' => ['post', route('tasks.return', $test->task), ['note' => 'Belum rapi, ulangi sisi kiri.']],
        'tasks.checkOff' => ['post', route('tasks.checkOff', $test->task), []],
        'item-qa.decide' => ['post', route('projects.item-qa.decide', $test->project), ['decision' => 'PERBAIKAN', 'item_ids' => [$test->item->id], 'note' => 'Engsel miring']],
        'item-qa.photo' => ['get', route('projects.item-qa.photo', ['project' => $test->project->id, 'check' => $test->check->id]), []],
    };
}

test('Sprint 29 routes refuse the roles that may not use them', function (string $route, string $role) {
    [$method, $url, $payload] = sprint29Route($this, $route);

    $this->actingAs($this->users[$role])->{$method}($url, $payload)->assertForbidden();
})->with([
    // Upah Tukang per item & draf pengecekan — the Pengawas or the Admin Proyek only (Sub 08/09).
    ...collect(['work-items.update', 'work-items.teamLead', 'work-check.draft'])
        ->crossJoin(['PM', 'QA_LAIN', 'LOGISTICS', 'FINANCE', 'HR', 'FIELD_STAFF', 'MARKETING'])->all(),
    // Sending the pengecekan = asking the money — the Pengawas only, never the Admin Proyek (H3).
    ...collect(['weekly-reports.store'])->crossJoin(['ADMIN_PROYEK', 'PM', 'QA_LAIN', 'LOGISTICS', 'FINANCE', 'HR', 'FIELD_STAFF'])->all(),
    // Upah Tukang decisions — the project's PM only.
    ...collect(['supervisor-payments.decide'])->crossJoin(['ADMIN_PROYEK', 'QA', 'QA_LAIN', 'LOGISTICS', 'FINANCE', 'HR', 'FIELD_STAFF'])->all(),
    // Daily target checks — Pengawas / PM side / Admin Proyek; never another QA, the Tukang or the back office (Sub 04).
    ...collect(['tasks.approve', 'tasks.return', 'tasks.checkOff'])->crossJoin(['QA_LAIN', 'LOGISTICS', 'FINANCE', 'HR', 'FIELD_STAFF', 'MARKETING'])->all(),
    // QA per item — the Pengawas only (Sub 12, H3); its photo — the Timeline & QC roles.
    ...collect(['item-qa.decide'])->crossJoin(['ADMIN_PROYEK', 'PM', 'QA_LAIN', 'LOGISTICS', 'FINANCE', 'HR', 'FIELD_STAFF'])->all(),
    ...collect(['item-qa.photo'])->crossJoin(['LOGISTICS', 'FINANCE', 'HR', 'FIELD_STAFF', 'MARKETING'])->all(),
]);
