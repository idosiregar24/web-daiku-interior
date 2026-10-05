<?php

use App\Enums\InvoiceStatus;
use App\Enums\KpiPeriodStatus;
use App\Enums\LeadStatus;
use App\Enums\MaterialRequestStatus;
use App\Enums\OvertimeStatus;
use App\Enums\QaStatus;
use App\Enums\QuotationStatus;
use App\Enums\ReviewStatus;
use App\Enums\TaskStatus;
use App\Models\DailyTaskForm;
use App\Models\Invoice;
use App\Models\KpiPeriod;
use App\Models\KpiScore;
use App\Models\Lead;
use App\Models\OvertimeRequest;
use App\Models\PerformanceReview;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\QaForm;
use App\Models\Quotation;
use App\Models\Task;
use App\Models\User;
use App\Services\ActionInboxService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

afterEach(fn () => Carbon::setTestNow());

function inboxUser(string ...$roles): User
{
    $user = User::factory()->create();
    $user->assignRole($roles);

    return $user;
}

/** @return array<string, mixed>|null */
function inboxGroup(User $user, string $key): ?array
{
    return collect(app(ActionInboxService::class)->for($user, fresh: true))->firstWhere('key', $key);
}

function inboxRequest(Project $project, MaterialRequestStatus $status): ProjectMaterial
{
    return ProjectMaterial::factory()->custom()->create([
        'project_id' => $project->id,
        'request_channel' => ProjectMaterial::CHANNEL_TIM,
        'request_status' => $status->value,
        'requested_by' => User::factory()->create()->id,
        'submitted_at' => now(),
    ]);
}

// ── CEO ─────────────────────────────────────────────────────────────────

test('the CEO queue of RAB waiting for a decision matches the filtered quotation list', function () {
    $ceo = inboxUser('CEO');
    Quotation::factory()->count(2)->create(['status' => QuotationStatus::WaitingCeo->value]);
    Quotation::factory()->create(['status' => QuotationStatus::Submitted->value]);

    $group = inboxGroup($ceo, 'quotation-ceo');

    expect($group['count'])->toBe(2)
        ->and($group['routeName'])->toBe('quotations.index')
        ->and($group['items'])->toHaveCount(2);

    $this->actingAs($ceo)
        ->get(route('quotations.index', ['status' => QuotationStatus::WaitingCeo->value]))
        ->assertInertia(fn (Assert $page) => $page->where('quotations.total', $group['count']));
});

test('a queue lists at most five items but counts them all, busiest queue first', function () {
    $ceo = inboxUser('CEO');
    Quotation::factory()->count(7)->create(['status' => QuotationStatus::WaitingCeo->value]);
    openingFor(Quotation::factory()->approved()->create());

    $groups = app(ActionInboxService::class)->for($ceo, fresh: true);

    expect(collect($groups)->pluck('key')->all())->toBe(['quotation-ceo', 'project-opening'])
        ->and($groups[0]['count'])->toBe(7)
        ->and($groups[0]['items'])->toHaveCount(ActionInboxService::ITEMS_PER_GROUP);
});

// ── PM / Asisten PM ─────────────────────────────────────────────────────

test("a PM's material queue holds only their projects, same as the Pengajuan Barang page", function () {
    $pm = inboxUser('PM');
    $own = Project::factory()->create(['pm_id' => $pm->id]);
    inboxRequest($own, MaterialRequestStatus::MenungguPm);
    inboxRequest($own, MaterialRequestStatus::MenungguPm);
    inboxRequest($own, MaterialRequestStatus::Diajukan);
    inboxRequest(Project::factory()->create(), MaterialRequestStatus::MenungguPm);

    $group = inboxGroup($pm, 'material-pm');

    expect($group['count'])->toBe(2);

    $this->actingAs($pm)
        ->get(route('logistics.material-requests.index'))
        ->assertInertia(fn (Assert $page) => $page->where('counts.MENUNGGU_PM', $group['count']));
});

test('an Asisten PM only sees the projects assigned to them', function () {
    $assistant = inboxUser('ASISTEN_PM');
    $assigned = Project::factory()->create(['assistant_pm_id' => $assistant->id]);
    $other = Project::factory()->create();
    inboxRequest($assigned, MaterialRequestStatus::MenungguPm);
    inboxRequest($other, MaterialRequestStatus::MenungguPm);
    QaForm::factory()->create(['project_id' => $assigned->id, 'status' => QaStatus::Rejected->value, 'reviewed_at' => now()]);
    QaForm::factory()->create(['project_id' => $other->id, 'status' => QaStatus::Rejected->value, 'reviewed_at' => now()]);
    OvertimeRequest::factory()->create(['project_id' => $assigned->id]);

    expect(inboxGroup($assistant, 'material-pm')['count'])->toBe(1)
        ->and(inboxGroup($assistant, 'qa-rejected')['count'])->toBe(1)
        ->and(inboxGroup($assistant, 'qa-rejected')['items'][0]['href'])->toContain('tab=qa')
        // Overtime is decided by the PM alone (pmApprove is `role:PM`).
        ->and(inboxGroup($assistant, 'overtime-pm'))->toBeNull();
});

test("the PM's overtime queue matches the PENDING overtime list", function () {
    $pm = inboxUser('PM');
    OvertimeRequest::factory()->count(3)->create();
    OvertimeRequest::factory()->create(['status' => OvertimeStatus::PendingFinance->value]);

    $group = inboxGroup($pm, 'overtime-pm');

    expect($group['count'])->toBe(3);

    $this->actingAs($pm)
        ->get(route('overtime.index', ['status' => OvertimeStatus::Pending->value]))
        ->assertInertia(fn (Assert $page) => $page->where('overtimeRequests.total', $group['count']));
});

// ── Finance / QA / Logistik ─────────────────────────────────────────────

test("Finance's verification queue matches the Verifikasi Pembayaran page", function () {
    $finance = inboxUser('FINANCE');
    $project = Project::factory()->create();
    foreach ([InvoiceStatus::MenungguVerifikasi, InvoiceStatus::MenungguVerifikasi, InvoiceStatus::Diterbitkan] as $i => $status) {
        Invoice::create([
            'number' => "INV-INBOX-{$i}",
            'lead_id' => $project->lead_id,
            'project_id' => $project->id,
            'type' => 'DP',
            'amount' => 1_000_000,
            'due_date' => now()->toDateString(),
            'status' => $status->value,
            'issued_by' => $finance->id,
            'issued_at' => now(),
            'proof_submitted_at' => now(),
        ]);
    }

    $group = inboxGroup($finance, 'invoice-verify');

    expect($group['count'])->toBe(2)->and($group['routeName'])->toBe('finance.invoices.verification');

    $this->actingAs($finance)
        ->get(route('finance.invoices.verification'))
        ->assertInertia(fn (Assert $page) => $page->where('invoices.total', $group['count']));
});

test("QA's queue matches the PENDING QA list, Logistik gets the DIAJUKAN requests", function () {
    $qa = inboxUser('QA');
    QaForm::factory()->count(2)->create();
    QaForm::factory()->create(['status' => QaStatus::Approved->value]);

    $group = inboxGroup($qa, 'qa-pending');
    expect($group['count'])->toBe(2);

    $this->actingAs($qa)
        ->get(route('qa-forms.index', ['status' => QaStatus::Pending->value]))
        ->assertInertia(fn (Assert $page) => $page->where('qaForms.total', $group['count']));

    $logistics = inboxUser('LOGISTICS');
    inboxRequest(Project::factory()->create(), MaterialRequestStatus::Diajukan);
    inboxRequest(Project::factory()->create(), MaterialRequestStatus::MenungguPm);

    expect(inboxGroup($logistics, 'material-logistics')['count'])->toBe(1);
});

// ── Marketing / Estimator ───────────────────────────────────────────────

test("Marketing's follow-ups: own live leads due today or earlier", function () {
    $marketing = inboxUser('MARKETING');
    Lead::factory()->followUpOn(now()->subDay()->toDateString())->create(['assigned_to' => $marketing->id]);
    Lead::factory()->followUpOn(now()->toDateString())->create(['assigned_to' => $marketing->id]);
    Lead::factory()->followUpOn(now()->addDays(2)->toDateString())->create(['assigned_to' => $marketing->id]);
    Lead::factory()->followUpOn(now()->subDay()->toDateString())->create(['assigned_to' => $marketing->id, 'status' => LeadStatus::Lost->value]);
    Lead::factory()->followUpOn(now()->subDay()->toDateString())->create();

    expect(inboxGroup($marketing, 'follow-up')['count'])->toBe(2);
});

test('the Estimator sees requested RABs and versions returned for revision', function () {
    $estimator = inboxUser('ESTIMATOR');
    Quotation::factory()->create(['status' => QuotationStatus::Diminta->value]);
    Quotation::factory()->create(['status' => QuotationStatus::Draft->value, 'version' => 2]);
    Quotation::factory()->create(['status' => QuotationStatus::Draft->value, 'version' => 1]);

    expect(inboxGroup($estimator, 'quotation-requested')['count'])->toBe(1)
        ->and(inboxGroup($estimator, 'quotation-returned')['count'])->toBe(1);
});

// ── SDM ─────────────────────────────────────────────────────────────────

test('HR sees reviews the CEO returned and manual KPI values still empty', function () {
    $hr = inboxUser('HR');
    PerformanceReview::factory()->create(['return_note' => 'Lengkapi penilaian kualitatif']);
    PerformanceReview::factory()->create();
    PerformanceReview::factory()->status(ReviewStatus::Submitted)->create();
    $open = KpiPeriod::factory()->create();
    KpiScore::factory()->create(['kpi_period_id' => $open->id, 'actual' => null, 'score' => null, 'weighted_score' => null]);
    KpiScore::factory()->create(['kpi_period_id' => $open->id]);
    KpiScore::factory()->create([
        'kpi_period_id' => KpiPeriod::factory()->create(['status' => KpiPeriodStatus::Closed->value])->id,
        'actual' => null,
    ]);

    expect(inboxGroup($hr, 'review-returned')['count'])->toBe(1)
        ->and(inboxGroup($hr, 'kpi-manual')['count'])->toBe(1);
});

// ── Tukang ──────────────────────────────────────────────────────────────

test("a Tukang's missing daily forms match the Form Harian fill-in list", function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00', 'Asia/Jakarta')); // Monday
    $tukang = inboxUser('FIELD_STAFF');
    $tasks = Task::factory()->count(3)->create(['assignee_id' => $tukang->id, 'due_date' => now()->toDateString()]);
    Task::factory()->create(['assignee_id' => $tukang->id, 'status' => TaskStatus::Done->value]);
    DailyTaskForm::factory()->create(['task_id' => $tasks[0]->id, 'staff_id' => $tukang->id]);

    $group = inboxGroup($tukang, 'daily-form');

    expect($group['count'])->toBe(2)
        ->and(inboxGroup($tukang, 'task-today')['count'])->toBe(3);

    $this->actingAs($tukang)
        ->get(route('daily-forms.index'))
        ->assertInertia(fn (Assert $page) => $page->has('pendingTasks', $group['count']));
});

test('no daily-form queue on Sunday — there is no penalty then', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-04 10:00', 'Asia/Jakarta')); // Sunday
    $tukang = inboxUser('FIELD_STAFF');
    Task::factory()->create(['assignee_id' => $tukang->id]);

    expect(inboxGroup($tukang, 'daily-form'))->toBeNull();
});

// ── Empty ───────────────────────────────────────────────────────────────

test('roles without a queue, and SUPERADMIN, get nothing', function (string $role) {
    Quotation::factory()->create(['status' => QuotationStatus::WaitingCeo->value]);
    Quotation::factory()->create(['status' => QuotationStatus::Submitted->value]);
    QaForm::factory()->create();

    expect(app(ActionInboxService::class)->for(inboxUser($role), fresh: true))->toBe([]);
})->with(['DESIGNER', 'SUPERADMIN']);
