<?php

use App\Enums\FinanceCategory;
use App\Enums\InvoiceStatus;
use App\Enums\LeadSurveyStatus;
use App\Enums\QuotationStatus;
use App\Enums\QuotationType;
use App\Events\InvoiceVerified;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\FinanceTransaction;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\LeadSurvey;
use App\Models\Notification;
use App\Models\Quotation;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\QuotationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 12 Sub 6 — Marketing issues invoices, Finance verifies payment
 * (decisions #20–#21); a verified Jasa Survey invoice makes the survey
 * ready (decision #3).
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'Asia/Jakarta'));
    $this->seed(RoleSeeder::class);
    $this->marketing = invoiceUser('MARKETING');
    $this->finance = invoiceUser('FINANCE');
    $this->account = BankAccount::factory()->create(['is_active' => true]);
    $this->lead = Lead::factory()->create(['assigned_to' => $this->marketing->id]);
});

afterEach(fn () => Carbon::setTestNow());

function invoiceUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function approvedServiceRab(object $test, string $type = 'SURVEY', float $total = 1_500_000): Quotation
{
    return Quotation::factory()->create([
        'lead_id' => $test->lead->id,
        'type' => $type,
        'status' => QuotationStatus::ClientApproved->value,
        'total_amount' => $total,
    ]);
}

function issuedInvoice(object $test, string $type = 'SURVEY'): Invoice
{
    return app(InvoiceService::class)->issueForQuotation(approvedServiceRab($test, $type), ['due_date' => '2026-10-10'], $test->marketing);
}

function proofed(object $test, string $type = 'SURVEY'): Invoice
{
    return app(InvoiceService::class)->submitProof(issuedInvoice($test, $type), ['payment_proof_url' => 'https://drive.google.com/bukti'], $test->marketing);
}

// ── Terbitkan ────────────────────────────────────────────────────────────

test('Marketing issues the one invoice of an approved Jasa Survey RAB', function () {
    $quotation = approvedServiceRab($this);

    $this->actingAs($this->marketing)->post(route('quotations.invoices.store', $quotation), ['due_date' => '2026-10-10'])
        ->assertSessionHasNoErrors();

    $invoice = Invoice::sole();
    expect($invoice->number)->toBe('1/INV/Daiku/X/2026')
        ->and($invoice->type->value)->toBe('JASA_SURVEY')
        ->and((float) $invoice->amount)->toBe(1_500_000.0)
        ->and($invoice->status)->toBe(InvoiceStatus::Diterbitkan)
        ->and($invoice->issued_by)->toBe($this->marketing->id)
        ->and(AuditLog::where('action', 'finance.invoice_issued')->exists())->toBeTrue();

    $this->actingAs($this->marketing)->post(route('quotations.invoices.store', $quotation), ['due_date' => '2026-10-10'])
        ->assertSessionHasErrors('quotation');
    expect(Invoice::count())->toBe(1);
});

test('invoice numbers follow the yearly letter sequence (Sprint 15)', function () {
    issuedInvoice($this);
    issuedInvoice($this, 'DESAIN');

    expect(Invoice::orderBy('id')->pluck('number')->all())->toBe(['1/INV/Daiku/X/2026', '2/INV/Daiku/X/2026']);
});

test('no invoice before the client approved, and none straight from a RAB Proyek', function (string $type, string $status) {
    $quotation = Quotation::factory()->create(['lead_id' => $this->lead->id, 'type' => $type, 'status' => $status]);

    expect(fn () => app(InvoiceService::class)->issueForQuotation($quotation, ['due_date' => '2026-10-10'], $this->marketing))
        ->toThrow(ValidationException::class);
})->with([['SURVEY', 'SENT_TO_CLIENT'], ['DESAIN', 'READY_TO_SEND'], ['PROYEK', 'CLIENT_APPROVED']]);

test('only Marketing issues invoices', function (string $role) {
    $quotation = approvedServiceRab($this);

    $this->actingAs(invoiceUser($role))->post(route('quotations.invoices.store', $quotation), ['due_date' => '2026-10-10'])
        ->assertForbidden();
})->with(['CEO', 'FINANCE', 'ESTIMATOR', 'PM']);

// ── Bukti bayar ──────────────────────────────────────────────────────────

test('a payment proof link sends the invoice to Finance', function () {
    $invoice = issuedInvoice($this);

    $this->actingAs($this->marketing)->post(route('finance.invoices.proof', $invoice), ['payment_proof_url' => 'javascript:alert(1)'])
        ->assertSessionHasErrors('payment_proof_url');
    $this->actingAs($this->marketing)->post(route('finance.invoices.proof', $invoice), ['payment_proof_url' => 'https://drive.google.com/bukti'])
        ->assertSessionHasNoErrors();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::MenungguVerifikasi)
        ->and(Notification::where('user_id', $this->finance->id)->where('type', 'invoice_awaiting_verification')->exists())->toBeTrue();
});

test('only Marketing and Finance attach proofs', function (string $role, int $status) {
    $invoice = issuedInvoice($this);

    $this->actingAs(invoiceUser($role))->post(route('finance.invoices.proof', $invoice), ['payment_proof_url' => 'https://x.test/a'])
        ->assertStatus($status);
})->with([['MARKETING', 302], ['FINANCE', 302], ['CEO', 403], ['PM', 403], ['ESTIMATOR', 403]]);

// ── Tandai klien sudah bayar (Sprint 19 Sub 01) ──────────────────────────

test('the client can be marked paid without a proof link', function () {
    $invoice = issuedInvoice($this);

    $this->actingAs($this->marketing)->post(route('finance.invoices.proof', $invoice), ['payment_proof_url' => '', 'payment_note' => ''])
        ->assertSessionHasNoErrors();

    $invoice->refresh();
    expect($invoice->status)->toBe(InvoiceStatus::MenungguVerifikasi)
        ->and($invoice->payment_proof_url)->toBeNull()
        ->and($invoice->payment_note)->toBeNull()
        ->and($invoice->proof_submitted_by)->toBe($this->marketing->id);

    $notification = Notification::where('user_id', $this->finance->id)->where('type', 'invoice_awaiting_verification')->sole();
    expect($notification->message)->toContain('Tanpa link bukti — cocokkan dengan mutasi rekening.');

    $audit = AuditLog::where('action', 'finance.invoice_proof_submitted')->sole();
    expect($audit->new_values)->toMatchArray(['payment_proof_url' => null, 'payment_note' => null]);
});

test('a proof link, when given, must still be http/https', function (string $url) {
    $invoice = issuedInvoice($this);

    $this->actingAs($this->marketing)->post(route('finance.invoices.proof', $invoice), ['payment_proof_url' => $url])
        ->assertSessionHasErrors('payment_proof_url');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Diterbitkan);
})->with(['javascript:alert(1)', 'ftp://bank.test/bukti.pdf', 'bukan link']);

test('the payment note is saved, audited, sent to Finance and shown on its pages', function () {
    $invoice = issuedInvoice($this);

    $this->actingAs($this->marketing)->post(route('finance.invoices.proof', $invoice), ['payment_note' => '  Transfer BCA a.n. Budi, 12 Okt  '])
        ->assertSessionHasNoErrors();

    expect($invoice->fresh()->payment_note)->toBe('Transfer BCA a.n. Budi, 12 Okt')
        ->and(AuditLog::where('action', 'finance.invoice_proof_submitted')->sole()->new_values['payment_note'])->toBe('Transfer BCA a.n. Budi, 12 Okt')
        ->and(Notification::where('user_id', $this->finance->id)->sole()->message)->toContain('Catatan: Transfer BCA a.n. Budi, 12 Okt');

    foreach (['finance.invoices.verification', 'finance.invoices.index'] as $routeName) {
        $this->actingAs($this->finance)->get(route($routeName))
            ->assertInertia(fn (Assert $page) => $page
                ->where('invoices.data.0.id', $invoice->id)
                ->where('invoices.data.0.payment_note', 'Transfer BCA a.n. Budi, 12 Okt')
                ->where('invoices.data.0.payment_proof_url', null));
    }

    $this->actingAs($this->marketing)->post(route('finance.invoices.proof', issuedInvoice($this)), ['payment_note' => str_repeat('a', 501)])
        ->assertSessionHasErrors('payment_note');
});

// ── Verifikasi ───────────────────────────────────────────────────────────

test('Finance verifying books one income transaction on the chosen account', function () {
    $invoice = proofed($this);

    $this->actingAs($this->finance)->post(route('finance.invoices.verify', $invoice), [
        'bank_account_id' => $this->account->id,
        'paid_date' => '2026-10-04',
    ])->assertSessionHasNoErrors();

    $invoice->refresh();
    $transaction = FinanceTransaction::sole();

    expect($invoice->status)->toBe(InvoiceStatus::Terverifikasi)
        ->and($invoice->verified_by)->toBe($this->finance->id)
        ->and($invoice->finance_transaction_id)->toBe($transaction->id)
        ->and($transaction->type->value)->toBe('PEMASUKAN')
        ->and($transaction->kategori)->toBe(FinanceCategory::PendapatanSurvey)
        ->and((float) $transaction->amount)->toBe(1_500_000.0)
        ->and($transaction->bank_account_id)->toBe($this->account->id)
        ->and($transaction->date->toDateString())->toBe('2026-10-04')
        ->and(AuditLog::where('action', 'finance.invoice_verified')->exists())->toBeTrue()
        ->and(Notification::where('user_id', $this->marketing->id)->where('type', 'invoice_verified')->exists())->toBeTrue();

    // A second verification (double click, stale tab) books nothing more.
    $this->actingAs($this->finance)->post(route('finance.invoices.verify', $invoice), [
        'bank_account_id' => $this->account->id,
        'paid_date' => '2026-10-04',
    ])->assertSessionHasErrors('status');

    expect(FinanceTransaction::count())->toBe(1);
});

test('a Jasa Desain payment is booked as design income', function () {
    $invoice = app(InvoiceService::class)->verify(proofed($this, 'DESAIN'), ['bank_account_id' => $this->account->id, 'paid_date' => '2026-10-05'], $this->finance);

    expect($invoice->financeTransaction->kategori)->toBe(FinanceCategory::PendapatanDesain);
});

test('an inactive account or an invoice without proof cannot be verified', function () {
    $inactive = BankAccount::factory()->create(['is_active' => false]);

    expect(fn () => app(InvoiceService::class)->verify(proofed($this), ['bank_account_id' => $inactive->id, 'paid_date' => '2026-10-05'], $this->finance))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(InvoiceService::class)->verify(issuedInvoice($this), ['bank_account_id' => $this->account->id, 'paid_date' => '2026-10-05'], $this->finance))
        ->toThrow(ValidationException::class);

    expect(FinanceTransaction::count())->toBe(0);
});

test('Finance rejects a proof with a reason — back to Marketing', function () {
    $invoice = proofed($this);

    $this->actingAs($this->finance)->post(route('finance.invoices.reject', $invoice), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($this->finance)->post(route('finance.invoices.reject', $invoice), ['reason' => 'Dana belum masuk.'])->assertSessionHasNoErrors();

    $invoice->refresh();
    expect($invoice->status)->toBe(InvoiceStatus::Diterbitkan)
        ->and($invoice->reject_reason)->toBe('Dana belum masuk.')
        ->and(Notification::where('user_id', $this->marketing->id)->where('type', 'invoice_rejected')->exists())->toBeTrue()
        ->and(FinanceTransaction::count())->toBe(0);

    // Marketing can send a new proof.
    app(InvoiceService::class)->submitProof($invoice, ['payment_proof_url' => 'https://drive.google.com/bukti-2'], $this->marketing);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::MenungguVerifikasi);
});

test('only Finance verifies or rejects', function (string $role) {
    $invoice = proofed($this);
    $user = invoiceUser($role);

    $this->actingAs($user)->post(route('finance.invoices.verify', $invoice), ['bank_account_id' => $this->account->id, 'paid_date' => '2026-10-05'])->assertForbidden();
    $this->actingAs($user)->post(route('finance.invoices.reject', $invoice), ['reason' => 'x'])->assertForbidden();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::MenungguVerifikasi);
})->with(['MARKETING', 'CEO', 'PM', 'ESTIMATOR']);

test('verifying dispatches InvoiceVerified', function () {
    Event::fake([InvoiceVerified::class]);
    $invoice = proofed($this);

    app(InvoiceService::class)->verify($invoice, ['bank_account_id' => $this->account->id, 'paid_date' => '2026-10-05'], $this->finance);

    Event::assertDispatched(InvoiceVerified::class, fn (InvoiceVerified $event) => $event->invoice->is($invoice));
});

// ── Survey luar kota ─────────────────────────────────────────────────────

test('an outside-Pekanbaru survey becomes ready only when its Jasa Survey invoice is verified', function () {
    $survey = LeadSurvey::factory()->outsidePekanbaru()->create(['lead_id' => $this->lead->id]);
    $quotation = app(QuotationService::class)->request($this->lead, QuotationType::Survey, 'Survey Bangkinang', $this->marketing);
    $quotation->update(['status' => QuotationStatus::ClientApproved->value, 'total_amount' => 750_000]);

    $invoice = app(InvoiceService::class)->issueForQuotation($quotation, ['due_date' => '2026-10-08'], $this->marketing);
    app(InvoiceService::class)->submitProof($invoice, ['payment_proof_url' => 'https://drive.google.com/bukti'], $this->marketing);
    expect($survey->fresh()->status)->toBe(LeadSurveyStatus::MenungguBayar);

    app(InvoiceService::class)->verify($invoice, ['bank_account_id' => $this->account->id, 'paid_date' => '2026-10-05'], $this->finance);

    expect($survey->fresh()->status)->toBe(LeadSurveyStatus::Siap)
        ->and(Notification::where('user_id', $this->marketing->id)->where('type', 'lead_survey_ready')->exists())->toBeTrue();
});

test('the client approving a service RAB tells Marketing to issue its invoice', function (string $type) {
    $quotation = Quotation::factory()->create(['lead_id' => $this->lead->id, 'type' => $type, 'status' => QuotationStatus::SentToClient->value, 'valid_until' => '2026-10-15']);

    app(QuotationService::class)->clientApprove(shareLinkFor($quotation, $this->marketing), true, '10.0.0.1', 'Test');

    expect(Notification::where('user_id', $this->marketing->id)->where('type', 'invoice_to_issue')->exists())->toBeTrue();
})->with(['SURVEY', 'DESAIN']);

// ── Halaman ──────────────────────────────────────────────────────────────

test('the invoice list is for CEO, Marketing and Finance; the queue for CEO and Finance', function (string $role, int $list, int $queue) {
    proofed($this);
    $user = invoiceUser($role);

    $this->actingAs($user)->get(route('finance.invoices.index'))->assertStatus($list);
    $this->actingAs($user)->get(route('finance.invoices.verification'))->assertStatus($queue);
})->with([
    ['CEO', 200, 200],
    ['MARKETING', 200, 403],
    ['FINANCE', 200, 200],
    ['PM', 403, 403],
    ['ESTIMATOR', 403, 403],
]);

test('the verification queue shows only invoices with a proof, with Finance actions', function () {
    issuedInvoice($this);
    $waiting = proofed($this, 'DESAIN');

    $this->actingAs($this->finance)->get(route('finance.invoices.verification'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Finance/Invoices/Index')
            ->where('mode', 'verification')
            ->has('invoices.data', 1)
            ->where('invoices.data.0.id', $waiting->id)
            ->where('canVerify', true)
            ->has('bankAccounts', 1));
});

test('the invoice PDF renders', function () {
    $invoice = issuedInvoice($this);

    $response = $this->actingAs($this->marketing)->get(route('finance.invoices.pdf', $invoice))->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});

test('the quotation page offers "Terbitkan Invoice" to Marketing on an approved service RAB', function () {
    $quotation = approvedServiceRab($this);

    $this->actingAs($this->marketing)->get(route('quotations.show', $quotation))
        ->assertInertia(fn (Assert $page) => $page->where('canIssueInvoice', true)->has('invoices', 0));

    app(InvoiceService::class)->issueForQuotation($quotation, ['due_date' => '2026-10-10'], $this->marketing);

    $this->actingAs($this->marketing)->get(route('quotations.show', $quotation))
        ->assertInertia(fn (Assert $page) => $page->where('canIssueInvoice', false)->has('invoices', 1));
});

test('the invoices migration rolls back', function () {
    $migration = require database_path('migrations/2026_10_05_094413_create_invoices_table.php');

    $migration->down();
    expect(Schema::hasTable('invoices'))->toBeFalse();
    $migration->up();
    expect(Schema::hasTable('invoices'))->toBeTrue();
});
