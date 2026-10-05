<?php

use App\Enums\InvoiceStatus;
use App\Enums\LeadStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\QuotationStatus;
use App\Enums\TerminStatus;
use App\Jobs\TerminInvoiceReminderJob;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\FinanceTransaction;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Milestone;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectOpening;
use App\Models\Quotation;
use App\Models\Termin;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\ProjectService;
use App\Services\QuotationService;
use App\Services\TerminService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 12 Sub 7 — "Buka Proyek" by the CEO, termins from the approved
 * payment scheme, termin invoices by Marketing (decisions #12, #19–#21).
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00', 'Asia/Jakarta'));
    $this->seed(RoleSeeder::class);
    $this->ceo = openingUser('CEO');
    $this->pm = openingUser('PM');
    $this->marketing = openingUser('MARKETING');
    $this->finance = openingUser('FINANCE');
    $this->account = BankAccount::factory()->create(['is_active' => true]);
    $this->lead = Lead::factory()->create(['status' => LeadStatus::DealDesain->value, 'assigned_to' => $this->marketing->id, 'client_name' => 'Budi']);
});

afterEach(fn () => Carbon::setTestNow());

function openingUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** A RAB Proyek of 100.000.000 with DP 30% / Termin 33,33% (milestone "Produksi") / tanggal 16,67% / Pelunasan 20% (completion), approved on its link. */
function approvedProjectRab(object $test): Quotation
{
    $service = app(QuotationService::class);
    $quotation = Quotation::factory()->create(['lead_id' => $test->lead->id, 'type' => 'PROYEK', 'status' => QuotationStatus::Draft->value]);
    $service->saveRab($quotation, ['items' => [['description' => 'Kitchen set', 'qty' => 1, 'unit_id' => unitId('set'), 'unit_price' => 100_000_000]]]);
    $service->savePaymentTerms($quotation, [
        ['label' => 'DP', 'percentage' => 30, 'trigger' => 'DI_MUKA'],
        ['label' => 'Termin 2', 'percentage' => 33.33, 'trigger' => 'MILESTONE', 'milestone_name' => 'Produksi'],
        ['label' => 'Termin 3', 'percentage' => 16.67, 'trigger' => 'TANGGAL', 'due_date' => '2026-11-07'],
        ['label' => 'Pelunasan', 'percentage' => 20, 'trigger' => 'PROYEK_SELESAI'],
    ]);
    $quotation->refresh()->update(['status' => QuotationStatus::SentToClient->value, 'valid_until' => '2026-10-19']);
    $service->clientApprove(shareLinkFor($quotation, $test->marketing), true, '10.0.0.1', 'Test');

    return $quotation->fresh();
}

function openProject(object $test, ?Quotation $quotation = null): Project
{
    $quotation ??= approvedProjectRab($test);

    return app(ProjectService::class)->openFromQuotation(ProjectOpening::where('quotation_id', $quotation->id)->sole(), [
        'name' => 'Proyek Budi',
        'pm_id' => $test->pm->id,
        'start_date' => '2026-10-10',
    ], $test->ceo);
}

// ── Opening ──────────────────────────────────────────────────────────────

test('the client approving a RAB Proyek queues one "Buka Proyek" for the CEO', function () {
    $quotation = approvedProjectRab($this);

    $opening = ProjectOpening::sole();
    expect($opening->quotation_id)->toBe($quotation->id)
        ->and($opening->status)->toBe(ProjectOpening::STATUS_WAITING)
        ->and(Notification::where('user_id', $this->ceo->id)->where('type', 'project_opening_pending')->count())->toBe(1);
});

test('service RABs never queue a project', function (string $type) {
    $quotation = Quotation::factory()->create(['lead_id' => $this->lead->id, 'type' => $type, 'status' => 'SENT_TO_CLIENT', 'valid_until' => '2026-10-19']);
    app(QuotationService::class)->clientApprove(shareLinkFor($quotation), true, '10.0.0.1', 'Test');

    expect(ProjectOpening::count())->toBe(0);
})->with(['SURVEY', 'DESAIN']);

test('opening creates the project and copies the scheme into termins', function () {
    $assistant = openingUser('ASISTEN_PM');
    $quotation = approvedProjectRab($this);

    $this->actingAs($this->ceo)->post(route('projects.openings.open', ProjectOpening::sole()), [
        'name' => 'Proyek Budi',
        'pm_id' => $this->pm->id,
        'assistant_pm_id' => $assistant->id,
        'start_date' => '2026-10-10',
    ])->assertSessionHasNoErrors();

    $project = Project::sole();
    expect($project->quotation_id)->toBe($quotation->id)
        ->and($project->assistant_pm_id)->toBe($assistant->id)
        ->and((float) $project->contract_value)->toBe(100_000_000.0)
        ->and(ProjectOpening::sole()->only(['status', 'project_id', 'opened_by']))->toBe(['status' => 'DIBUKA', 'project_id' => $project->id, 'opened_by' => $this->ceo->id])
        ->and(AuditLog::where('action', 'project.opened')->exists())->toBeTrue();

    $termins = $project->termins()->orderBy('termin_number')->get();
    expect($termins->map(fn (Termin $t) => [$t->termin_number, (float) $t->percentage, (float) $t->amount, $t->trigger->value, $t->scheduled_date?->toDateString(), $t->milestone_name])->all())
        ->toBe([
            [1, 30.0, 30_000_000.0, 'DI_MUKA', '2026-10-10', null],
            [2, 33.33, 33_330_000.0, 'MILESTONE', null, 'Produksi'],
            [3, 16.67, 16_670_000.0, 'TANGGAL', '2026-11-07', null],
            [4, 20.0, 20_000_000.0, 'PROYEK_SELESAI', null, null],
        ])
        ->and($termins->sum(fn (Termin $t) => (float) $t->amount))->toBe(100_000_000.0)
        ->and($termins->pluck('status')->unique()->all())->toBe([TerminStatus::Scheduled]);
});

test('only the CEO opens, once, with a real PM and Asisten PM', function () {
    approvedProjectRab($this);
    $opening = ProjectOpening::sole();
    $payload = ['name' => 'Proyek Budi', 'pm_id' => $this->pm->id, 'start_date' => '2026-10-10'];

    $this->actingAs($this->pm)->post(route('projects.openings.open', $opening), $payload)->assertForbidden();
    $this->actingAs($this->ceo)->post(route('projects.openings.open', $opening), ['pm_id' => $this->marketing->id] + $payload)->assertSessionHasErrors('pm_id');
    $this->actingAs($this->ceo)->post(route('projects.openings.open', $opening), ['assistant_pm_id' => $this->pm->id] + $payload)->assertSessionHasErrors('assistant_pm_id');

    $this->actingAs($this->ceo)->post(route('projects.openings.open', $opening), $payload)->assertSessionHasNoErrors();
    expect(fn () => app(ProjectService::class)->openFromQuotation($opening->fresh(), $payload, $this->ceo))->toThrow(ValidationException::class);
    expect(Project::count())->toBe(1);
});

test('the manual project and termin routes are gone', function () {
    expect(Route::has('projects.store'))->toBeFalse()
        ->and(Route::has('projects.termins.store'))->toBeFalse();
});

test('the CEO gets the pop-up on any page; others never do', function () {
    approvedProjectRab($this);

    $this->actingAs($this->ceo)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('pendingProjectOpenings.openings', 1)
            ->where('pendingProjectOpenings.openings.0.lead.client_name', 'Budi')
            ->has('pendingProjectOpenings.projectManagers'));

    $this->actingAs($this->pm)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('pendingProjectOpenings', null));

    openProject($this, Quotation::sole());
    $this->actingAs($this->ceo)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('pendingProjectOpenings', null));
});

test('the project list shows "Menunggu Dibuka" to CEO and PM, only the CEO may open', function (string $role, bool $canOpen) {
    approvedProjectRab($this);

    $this->actingAs(openingUser($role))->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page->has('pendingOpenings', 1)->where('canOpenProjects', $canOpen));
})->with([['CEO', true], ['PM', false]]);

// ── Invoice termin ───────────────────────────────────────────────────────

test('Marketing invoices termins: DP, termin, pelunasan', function () {
    $project = openProject($this);
    $termins = $project->termins()->orderBy('termin_number')->get();
    $service = app(InvoiceService::class);

    $types = $termins->map(fn (Termin $termin) => $service->issueForTermin($termin, ['due_date' => '2026-10-20'], $this->marketing)->type->value)->all();

    expect($types)->toBe(['DP', 'TERMIN', 'TERMIN', 'PELUNASAN'])
        ->and($termins[0]->fresh()->status)->toBe(TerminStatus::Invoiced)
        ->and($termins[0]->fresh()->invoice_id)->toBe(Invoice::where('type', 'DP')->value('id'))
        ->and(Invoice::where('type', 'DP')->sole()->project_id)->toBe($project->id);

    expect(fn () => $service->issueForTermin($termins[0]->fresh(), ['due_date' => '2026-10-20'], $this->marketing))->toThrow(ValidationException::class);
});

test('only Marketing issues termin invoices', function (string $role) {
    $termin = openProject($this)->termins()->first();

    $this->actingAs(openingUser($role))->post(route('finance.termins.invoices.store', $termin), ['due_date' => '2026-10-20'])->assertForbidden();
})->with(['CEO', 'PM', 'FINANCE']);

test('verifying a termin invoice pays the termin, booking the income once', function () {
    $termin = openProject($this)->termins()->orderBy('termin_number')->first();
    $service = app(InvoiceService::class);

    $invoice = $service->issueForTermin($termin, ['due_date' => '2026-10-20'], $this->marketing);
    $service->submitProof($invoice, 'https://drive.google.com/bukti', $this->marketing);
    $service->verify($invoice, ['bank_account_id' => $this->account->id, 'paid_date' => '2026-10-05'], $this->finance);

    $termin->refresh();
    expect($termin->status)->toBe(TerminStatus::Paid)
        ->and((float) $termin->dp_amount)->toBe(30_000_000.0)
        ->and((float) $termin->sisa_piutang)->toBe(0.0)
        ->and(FinanceTransaction::count())->toBe(1)
        ->and(FinanceTransaction::sole()->kategori->value)->toBe('DOWN_PAYMENT')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Terverifikasi);
});

test('an invoiced termin cannot be paid directly by Finance (no double booking)', function () {
    $termin = openProject($this)->termins()->first();
    app(InvoiceService::class)->issueForTermin($termin, ['due_date' => '2026-10-20'], $this->marketing);

    expect(fn () => app(TerminService::class)->recordPayment($termin->fresh(), [
        'type' => 'PELUNASAN', 'amount' => null, 'bank_account_id' => $this->account->id, 'paid_date' => '2026-10-05',
    ], $this->finance))->toThrow(ValidationException::class, 'Termin ini ditagih lewat invoice');
});

// ── Pengingat ────────────────────────────────────────────────────────────

test('Marketing is reminded once per termin when its trigger is reached', function () {
    $project = openProject($this);
    $service = app(TerminService::class);
    $reminded = fn () => Notification::where('user_id', $this->marketing->id)->where('type', 'termin_invoice_due')->count();

    // Day 1: only the DP (di muka).
    expect($service->remindInvoices())->toBe(1)->and($reminded())->toBe(1);
    expect($service->remindInvoices())->toBe(0);

    // The "Produksi" milestone completes → termin 2, linked to it.
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'name' => 'produksi ', 'status' => MilestoneStatus::Completed->value]);
    expect($service->remindInvoices())->toBe(1)
        ->and($project->termins()->where('termin_number', 2)->value('milestone_id'))->toBe($milestone->id);

    // Its date → termin 3.
    Carbon::setTestNow(Carbon::parse('2026-11-07 07:30:00', 'Asia/Jakarta'));
    expect($service->remindInvoices())->toBe(1);

    // Completion → pelunasan.
    $project->update(['status' => ProjectStatus::Completed->value]);
    (new TerminInvoiceReminderJob)->handle($service);

    expect($reminded())->toBe(4)->and($service->remindInvoices())->toBe(0);
});

test('an already invoiced termin is not reminded', function () {
    $termin = openProject($this)->termins()->first();
    app(InvoiceService::class)->issueForTermin($termin, ['due_date' => '2026-10-20'], $this->marketing);

    expect(app(TerminService::class)->remindInvoices())->toBe(0);
});

// ── Halaman proyek ───────────────────────────────────────────────────────

test('Marketing sees the termins and documents but no allocation or supplier debts', function () {
    $project = openProject($this);

    $this->actingAs($this->marketing)->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canViewTermins', true)
            ->where('canIssueTerminInvoices', true)
            ->where('canViewFinanceSummary', false)
            ->where('allocationBreakdown', [])
            ->where('supplierDebts', [])
            ->has('termins', 4)
            ->where('documents.quotation.id', $project->quotation_id));
});

test('the Documents tab lists the RAB Fix and every invoice of the lead', function () {
    $project = openProject($this);
    app(InvoiceService::class)->issueForTermin($project->termins()->first(), ['due_date' => '2026-10-20'], $this->marketing);

    $this->actingAs($this->finance)->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page->has('documents.invoices', 1)->where('documents.quotation.version', 1));

    $this->actingAs(openingUser('QA'))->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page->where('documents', null)->where('canViewTermins', false));
});

// ── Migrasi ──────────────────────────────────────────────────────────────

test('the Sprint 12 project/termin migrations roll back', function () {
    $columns = require database_path('migrations/2026_10_05_095840_add_scheme_columns_to_projects_and_termins.php');
    $openings = require database_path('migrations/2026_10_05_095840_create_project_openings_table.php');

    $openings->down();
    $columns->down();
    expect(Schema::hasColumn('termins', 'trigger'))->toBeFalse()
        ->and(Schema::hasColumn('projects', 'quotation_id'))->toBeFalse()
        ->and(Schema::hasTable('project_openings'))->toBeFalse();

    $columns->up();
    $openings->up();
    expect(Schema::hasColumn('termins', 'invoice_id'))->toBeTrue();
});
