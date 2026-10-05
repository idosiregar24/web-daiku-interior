<?php

use App\Enums\InvoiceType;
use App\Enums\LeadStatus;
use App\Enums\QuotationStatus;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectOpening;
use App\Models\Quotation;
use App\Models\Termin;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\ProjectBudgetService;
use App\Services\ProjectService;
use App\Services\QuotationService;
use App\Services\TerminService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 12 Sub 12 — RAB Tambahan (decision #29, D7): requested from a
 * running project, the usual RAB flow (Estimator → PM → CEO → client
 * link), and its approval adds to the project instead of opening one.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'Asia/Jakarta'));
    $this->seed(RoleSeeder::class);
    $this->marketing = addendumUser('MARKETING');
    $this->estimator = addendumUser('ESTIMATOR');
    $this->pm = addendumUser('PM');
    $this->ceo = addendumUser('CEO');
    $this->finance = addendumUser('FINANCE');

    // A running project from a 100 jt RAB Fix: DP 30 % + pelunasan 70 %.
    $lead = Lead::factory()->create(['status' => LeadStatus::Closing->value, 'assigned_to' => $this->marketing->id]);
    $this->rabFix = Quotation::factory()->approved()->create(['lead_id' => $lead->id, 'items_total' => 100_000_000, 'total_amount' => 100_000_000]);
    $this->rabFix->paymentTerms()->create(['sequence' => 1, 'label' => 'DP', 'percentage' => 30, 'amount' => 30_000_000, 'trigger' => 'DI_MUKA']);
    $this->rabFix->paymentTerms()->create(['sequence' => 2, 'label' => 'Pelunasan', 'percentage' => 70, 'amount' => 70_000_000, 'trigger' => 'PROYEK_SELESAI']);
    $this->project = Project::factory()->create([
        'lead_id' => $lead->id,
        'quotation_id' => $this->rabFix->id,
        'pm_id' => $this->pm->id,
        'contract_value' => 100_000_000,
        'start_date' => '2026-09-01',
    ]);
    app(TerminService::class)->createFromPaymentTerms($this->project, $this->rabFix->load('paymentTerms'));
});

afterEach(fn () => Carbon::setTestNow());

function addendumUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** Runs a requested addendum through Estimator → PM → CEO → Marketing → client link; returns it approved. */
function approvedAddendum(object $test, ?Quotation $addendum = null): Quotation
{
    $service = app(QuotationService::class);
    $addendum ??= $service->requestAddendum($test->project, 'Plafon membran 8,84 m².', $test->marketing);

    $service->startDraft($addendum, $test->estimator);
    $service->replaceItems($addendum, [
        ['description' => 'Plafon Membran', 'qty' => 8.84, 'unit_id' => unitId('m2'), 'unit_price' => 500_000],
        ['description' => 'Plafon Topian Meja Bar', 'qty' => 1, 'unit_id' => unitId('ls'), 'unit_price' => 5_580_000],
    ]);
    $service->savePaymentTerms($addendum, [
        ['label' => 'DP Tambahan', 'percentage' => 50, 'trigger' => 'DI_MUKA'],
        ['label' => 'Pelunasan Tambahan', 'percentage' => 50, 'trigger' => 'PROYEK_SELESAI'],
    ]);
    $service->submit($addendum);
    reviewQuotation($addendum->fresh(), $test->pm);
    expect($addendum->fresh()->status)->toBe(QuotationStatus::WaitingCeo);
    reviewQuotation($addendum->fresh(), $test->ceo);
    $service->sendToMarketing($addendum->fresh(), $test->estimator);
    $service->sendToClient($addendum->fresh(), $test->marketing);

    $test->post(route('public.quotation.approve', $addendum->fresh()->currentShareLink()->token), ['agree' => true])
        ->assertSessionHasNoErrors();

    return $addendum->fresh();
}

// ── Request ─────────────────────────────────────────────────────────────

test('Marketing or the project PM asks for a RAB Tambahan; the Estimator is told', function (string $who) {
    $user = $who === 'pm' ? $this->pm : $this->marketing;

    $this->actingAs($user)->post(route('projects.addenda.store', $this->project), ['note' => 'Plafon membran 8,84 m².'])
        ->assertRedirect();

    $addendum = Quotation::whereNotNull('parent_quotation_id')->sole();
    expect($addendum->type->value)->toBe('PROYEK')
        ->and($addendum->status)->toBe(QuotationStatus::Diminta)
        ->and($addendum->parent_quotation_id)->toBe($this->rabFix->id)
        ->and($addendum->project_id)->toBe($this->project->id)
        ->and($addendum->requested_by)->toBe($user->id)
        ->and(Notification::where('user_id', $this->estimator->id)->where('type', 'quotation_requested')->exists())->toBeTrue()
        // The lead's project offer is still the RAB Fix.
        ->and($this->project->lead->quotation->id)->toBe($this->rabFix->id);
})->with(['marketing', 'pm']);

test('only Marketing and the project PM may ask', function (string $role) {
    $this->actingAs(addendumUser($role))->post(route('projects.addenda.store', $this->project), ['note' => 'Plafon membran.'])
        ->assertForbidden();

    expect(Quotation::whereNotNull('parent_quotation_id')->exists())->toBeFalse();
})->with(['another PM' => ['PM'], 'CEO', 'ESTIMATOR', 'FINANCE', 'ASISTEN_PM']);

test('a RAB Tambahan needs a note, a RAB Fix, a running project, and one at a time', function () {
    $this->actingAs($this->marketing)->post(route('projects.addenda.store', $this->project), ['note' => ''])->assertSessionHasErrors('note');

    $service = app(QuotationService::class);
    $service->requestAddendum($this->project, 'Plafon membran.', $this->marketing);
    expect(fn () => $service->requestAddendum($this->project, 'Rak dinding.', $this->marketing))->toThrow(ValidationException::class);

    $legacy = Project::factory()->create(['quotation_id' => null]);
    expect(fn () => $service->requestAddendum($legacy, 'Plafon.', $this->marketing))->toThrow(ValidationException::class);

    $closed = Project::factory()->create(['quotation_id' => Quotation::factory()->approved()->create()->id, 'status' => 'COMPLETED']);
    expect(fn () => $service->requestAddendum($closed, 'Plafon.', $this->marketing))->toThrow(ValidationException::class);
});

// ── Approval adds to the project (D7) ───────────────────────────────────

test('the client approving a RAB Tambahan adds value, TAMBAHAN termins and items — never a new project', function () {
    $addendum = approvedAddendum($this);

    expect((float) $addendum->total_amount)->toBe(10_000_000.0)
        ->and($addendum->status)->toBe(QuotationStatus::ClientApproved)
        ->and(ProjectOpening::count())->toBe(0)
        ->and(Project::count())->toBe(1)
        ->and((float) $this->project->fresh()->contract_value)->toBe(110_000_000.0)
        ->and($this->project->lead->fresh()->status)->toBe(LeadStatus::Closing);

    $termins = Termin::where('project_id', $this->project->id)->orderBy('termin_number')->get();
    expect($termins->pluck('termin_number')->all())->toBe([1, 2, 3, 4])
        ->and($termins->pluck('quotation_id')->all())->toBe([$this->rabFix->id, $this->rabFix->id, $addendum->id, $addendum->id])
        ->and((float) $termins[2]->amount)->toBe(5_000_000.0)
        ->and($termins[2]->scheduled_date->toDateString())->toBe('2026-10-05')
        ->and($termins[3]->scheduled_date)->toBeNull();

    expect(AuditLog::where('action', 'project.addendum_added')->exists())->toBeTrue()
        ->and(Notification::where('user_id', $this->pm->id)->where('type', 'project_addendum_added')->exists())->toBeTrue()
        ->and(Notification::where('user_id', $this->finance->id)->where('type', 'project_addendum_added')->exists())->toBeTrue()
        ->and(Notification::where('user_id', $this->marketing->id)->where('type', 'project_addendum_added')->exists())->toBeTrue();
});

test('an addendum termin is invoiced as TAMBAHAN; the RAB Fix pelunasan stays PELUNASAN', function () {
    $addendum = approvedAddendum($this);
    $invoices = app(InvoiceService::class);
    $termins = Termin::where('project_id', $this->project->id)->orderBy('termin_number')->get();

    $tambahan = $invoices->issueForTermin($termins[2], ['due_date' => '2026-10-10'], $this->marketing);
    $pelunasan = $invoices->issueForTermin($termins[1], ['due_date' => '2026-12-10'], $this->marketing);

    expect($tambahan->type)->toBe(InvoiceType::Tambahan)
        ->and($tambahan->quotation_id)->toBe($addendum->id)
        ->and((float) $tambahan->amount)->toBe(5_000_000.0)
        ->and($pelunasan->type)->toBe(InvoiceType::Pelunasan)
        ->and($pelunasan->quotation_id)->toBe($this->rabFix->id);
});

test('approved addendum items join "belum dialokasikan" and the summary totals', function () {
    Invoice::create([
        'number' => 'INV-ADD-0001', 'lead_id' => $this->project->lead_id, 'project_id' => $this->project->id, 'type' => 'DP',
        'amount' => 1, 'due_date' => '2026-10-05', 'status' => 'TERVERIFIKASI', 'issued_by' => $this->pm->id, 'issued_at' => now(),
    ]);
    $budget = app(ProjectBudgetService::class);

    // Requested but not approved yet — not allocatable.
    $pending = app(QuotationService::class)->requestAddendum($this->project, 'Plafon.', $this->marketing);
    expect($budget->sourceItems($this->project->fresh()))->toHaveCount(0);

    approvedAddendum($this, $pending);
    $overview = $budget->overview($this->project->fresh());

    expect(collect($overview['unallocatedItems'])->pluck('description')->all())->toBe(['Plafon Membran', 'Plafon Topian Meja Bar'])
        ->and(collect($overview['unallocatedItems'])->every(fn (array $item) => $item['addendum']))->toBeTrue()
        ->and($overview['summary']['rabTotal'])->toBe(110_000_000.0)
        ->and($overview['summary']['itemsTotal'])->toBe(110_000_000.0);
});

test('changing the contract value before any payment only re-derives the RAB Fix termins', function () {
    approvedAddendum($this);

    app(ProjectService::class)->update($this->project->fresh(), [
        'name' => $this->project->name,
        'start_date' => '2026-09-01',
        'contract_value' => 120_000_000,
        'status' => 'ACTIVE',
    ], $this->ceo);

    // Base = 120 jt − 10 jt addendum = 110 jt → DP 33 jt, pelunasan 77 jt; the addendum's stay 5 jt + 5 jt.
    expect(Termin::where('project_id', $this->project->id)->orderBy('termin_number')->pluck('amount')->map(fn ($a) => (float) $a)->all())
        ->toBe([33_000_000.0, 77_000_000.0, 5_000_000.0, 5_000_000.0]);
});

// ── Pages ───────────────────────────────────────────────────────────────

test('the project page lists the addenda and offers the request to the right people', function () {
    app(QuotationService::class)->requestAddendum($this->project, 'Plafon membran.', $this->marketing);

    $this->actingAs($this->marketing)->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page->has('documents.addenda', 1)->where('canRequestAddendum', true));
    $this->actingAs($this->pm)->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page->where('canRequestAddendum', true));
    $this->actingAs($this->ceo)->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page->where('canRequestAddendum', false));

    $addendum = Quotation::whereNotNull('parent_quotation_id')->sole();
    $this->actingAs($this->estimator)->get(route('quotations.show', $addendum))
        ->assertInertia(fn (Assert $page) => $page->where('quotation.project.id', $this->project->id)->where('quotation.parent.id', $this->rabFix->id));
});
