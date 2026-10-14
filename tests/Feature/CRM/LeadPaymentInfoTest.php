<?php

use App\Enums\PaymentTermTrigger;
use App\Enums\QuotationStatus;
use App\Enums\TerminStatus;
use App\Models\BankAccount;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\Termin;
use App\Models\User;
use App\Services\InvoiceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 23 Sub 10 (K12c) — "Informasi Pembayaran" on the lead detail:
 * every payment of the client with whose turn it is, a summary, and the
 * props only for CEO, Marketing and the PM of the lead's project (Q17).
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', 'Asia/Jakarta'));
    $this->seed(RoleSeeder::class);
    $this->marketing = lpUser('MARKETING');
    $this->finance = lpUser('FINANCE');
    $this->pm = lpUser('PM');
    $this->account = BankAccount::factory()->create(['is_active' => true, 'label' => 'BCA Daiku']);
    $this->lead = Lead::factory()->create(['assigned_to' => $this->marketing->id, 'client_name' => 'Bu Sari']);
});

afterEach(fn () => Carbon::setTestNow());

function lpUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * Jasa Survey paid (verified), Jasa Desain approved but not billed yet, and
 * the project "Kopi Oz": DP invoiced + marked paid (waiting for Finance),
 * termin 2 not billed and past its date, termin 3 waiting for its
 * timeline, pelunasan at completion.
 */
function lpScenario(object $test): Project
{
    $service = app(InvoiceService::class);

    $survey = Quotation::factory()->create(['lead_id' => $test->lead->id, 'type' => 'SURVEY', 'status' => QuotationStatus::ClientApproved->value, 'total_amount' => 500_000, 'client_approved_at' => '2026-09-01 10:00:00']);
    $surveyInvoice = $service->issueForQuotation($survey, ['due_date' => '2026-10-14'], $test->marketing);
    $service->submitProof($surveyInvoice, [], $test->marketing);
    $service->verify($surveyInvoice->fresh(), ['bank_account_id' => $test->account->id, 'paid_date' => '2026-10-10'], $test->finance);

    Quotation::factory()->create(['lead_id' => $test->lead->id, 'type' => 'DESAIN', 'status' => QuotationStatus::ClientApproved->value, 'total_amount' => 5_000_000, 'client_approved_at' => '2026-09-15 10:00:00']);

    $rab = Quotation::factory()->create(['lead_id' => $test->lead->id, 'type' => 'PROYEK', 'status' => QuotationStatus::ClientApproved->value, 'total_amount' => 100_000_000]);
    $project = Project::factory()->create(['lead_id' => $test->lead->id, 'name' => 'Kopi Oz', 'quotation_id' => $rab->id, 'pm_id' => $test->pm->id]);

    $termins = collect([
        [30, PaymentTermTrigger::DiMuka, '2026-10-20', null],
        [30, PaymentTermTrigger::Tanggal, '2026-10-10', null],
        [20, PaymentTermTrigger::Milestone, null, 'Pemasangan'],
        [20, PaymentTermTrigger::ProyekSelesai, null, null],
    ])->map(fn (array $row, int $i) => Termin::factory()->create([
        'project_id' => $project->id,
        'quotation_id' => $rab->id,
        'termin_number' => $i + 1,
        'trigger' => $row[1]->value,
        'milestone_name' => $row[3],
        'percentage' => $row[0],
        'amount' => $row[0] * 1_000_000,
        'scheduled_date' => $row[2],
        'status' => TerminStatus::Scheduled->value,
    ]));

    $dp = $service->issueForTermin($termins[0], ['due_date' => '2026-10-20'], $test->marketing);
    $service->submitProof($dp, ['payment_note' => 'Transfer BCA'], $test->marketing);

    return $project;
}

test('the lead shows every payment of the client in order, with whose turn it is', function () {
    lpScenario($this);

    $this->actingAs($this->marketing)->get(route('crm.leads.show', $this->lead))
        ->assertInertia(fn (Assert $page) => $page
            ->has('payments.items', 6)
            ->where('payments.items.0.label', 'Jasa Survey')
            ->where('payments.items.0.state', 'LUNAS')
            ->where('payments.items.0.paid_date', '2026-10-10')
            ->where('payments.items.0.bank_account', 'BCA Daiku')
            ->where('payments.items.0.pdf_url', fn ($url) => str_contains($url, '/pdf'))
            ->where('payments.items.1.label', 'Jasa Desain')
            ->where('payments.items.1.state', 'BELUM_DITAGIH')
            ->where('payments.items.1.domain', 'termin')
            ->where('payments.items.1.status', 'SCHEDULED')
            ->where('payments.items.2.label', 'DP')
            ->where('payments.items.2.context', 'Kopi Oz')
            ->where('payments.items.2.state', 'MENUNGGU_KONFIRMASI')
            ->where('payments.items.2.domain', 'invoice')
            ->where('payments.items.2.status', 'MENUNGGU_VERIFIKASI')
            ->where('payments.items.3.label', 'Termin 2 dari 4 · 30%')
            ->where('payments.items.3.state', 'BELUM_DITAGIH')
            ->where('payments.items.3.overdue', true)
            // Marketing can't open a termin bill (finance.termins.pdf is CEO/PM/Finance).
            ->where('payments.items.3.pdf_url', null)
            ->where('payments.items.4.label', 'Termin 3 dari 4 · 20%')
            ->where('payments.items.4.due_date', null)
            ->where('payments.items.4.schedule_note', 'Setelah timeline "Pemasangan" selesai')
            ->where('payments.items.5.label', 'Pelunasan')
            ->where('payments.items.5.schedule_note', 'Setelah proyek selesai')
            ->where('payments.summary.total', 105_500_000)
            ->where('payments.summary.paid', 500_000)
            ->where('payments.summary.awaiting_confirmation', 30_000_000)
            ->where('payments.summary.unpaid', 75_000_000)
            ->where('payments.summary.not_billed', 75_000_000)
            ->where('payments.summary.overdue', 30_000_000)
            ->where('payments.summary.overdue_count', 1));
});

test('an issued invoice past its due date is overdue and waits for the client', function () {
    $quotation = Quotation::factory()->create(['lead_id' => $this->lead->id, 'type' => 'DESAIN', 'status' => QuotationStatus::ClientApproved->value, 'total_amount' => 5_000_000]);
    $invoice = app(InvoiceService::class)->issueForQuotation($quotation, ['due_date' => '2026-10-14'], $this->marketing);
    Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', 'Asia/Jakarta'));

    $payments = app(InvoiceService::class)->leadPayments($this->lead, $this->marketing);

    expect($payments['items'])->toHaveCount(1)
        ->and($payments['items'][0])->toMatchArray([
            'invoice_number' => $invoice->number,
            'state' => 'BELUM_DIBAYAR',
            'domain' => 'invoice',
            'status' => 'DITERBITKAN',
            'overdue' => true,
        ])
        ->and($payments['summary']['overdue'])->toEqual(5_000_000);
});

test('the payment info is sent to CEO, Marketing and the PM of the lead\'s project only', function (string $who, bool $sees) {
    lpScenario($this);

    $user = match ($who) {
        'project PM' => $this->pm,
        default => lpUser($who),
    };

    $this->actingAs($user)->get(route('crm.leads.show', $this->lead))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $sees ? $page->has('payments.items', 6) : $page->where('payments', null));
})->with([
    ['CEO', true],
    ['MARKETING', true],
    ['SUPERADMIN', true],
    ['project PM', true],
    ['PM', false],
    ['DESIGNER', false],
    ['ESTIMATOR', false],
]);

test('the project PM gets the termin bills, never the invoice PDF', function () {
    lpScenario($this);

    $this->actingAs($this->pm)->get(route('crm.leads.show', $this->lead))
        ->assertInertia(fn (Assert $page) => $page
            // Jasa Survey invoice: no termin, and finance.invoices.pdf excludes PM.
            ->where('payments.items.0.pdf_url', null)
            ->where('payments.items.2.pdf_url', fn ($url) => str_contains($url, '/termins/'))
            ->where('payments.items.3.pdf_url', fn ($url) => str_contains($url, '/termins/')));
});

test('a lead without any payment gets an empty list', function () {
    $this->actingAs($this->marketing)->get(route('crm.leads.show', $this->lead))
        ->assertInertia(fn (Assert $page) => $page
            ->has('payments.items', 0)
            ->where('payments.summary.total', 0));
});
