<?php

use App\Enums\QuotationStatus;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\User;
use App\Services\ActionInboxService;
use App\Services\InvoiceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 17 Sub 07 — "Kirim Bukti Bayar" is reachable where the invoice
 * lives (RAB page, project) and from Marketing's "Perlu Tindakan", not only
 * from the Invoice menu.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 10:00:00', 'Asia/Jakarta'));
    $this->seed(RoleSeeder::class);
    $this->marketing = proofUser('MARKETING');
    $this->colleague = proofUser('MARKETING');
    $this->finance = proofUser('FINANCE');
    $this->lead = Lead::factory()->create(['assigned_to' => $this->marketing->id]);
});

afterEach(fn () => Carbon::setTestNow());

function proofUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function proofIssued(object $test, ?Lead $lead = null): Invoice
{
    $quotation = Quotation::factory()->create([
        'lead_id' => ($lead ?? $test->lead)->id,
        'type' => 'DESAIN',
        'status' => QuotationStatus::ClientApproved->value,
        'total_amount' => 5_000_000,
    ]);

    return app(InvoiceService::class)->issueForQuotation($quotation, ['due_date' => '2026-10-10'], $test->marketing);
}

function proofQueue(User $user): ?array
{
    return collect(app(ActionInboxService::class)->for($user))->firstWhere('key', 'invoice-proof');
}

test('an issued invoice waits in its Marketing\'s queue until the proof is sent, and comes back when Finance rejects it', function () {
    $invoice = proofIssued($this);
    proofIssued($this, Lead::factory()->create(['assigned_to' => $this->colleague->id]));

    $group = proofQueue($this->marketing);
    expect($group['count'])->toBe(1)
        ->and($group['items'][0]['href'])->toBe(route('finance.invoices.index', ['awaiting_proof' => 1, 'proof' => $invoice->id]));

    $service = app(InvoiceService::class);
    $service->submitProof($invoice, ['payment_proof_url' => 'https://drive.google.com/bukti'], $this->finance);
    expect(proofQueue($this->marketing))->toBeNull();

    $service->reject($invoice->fresh(), 'Nominal transfer kurang', $this->finance);
    expect(proofQueue($this->marketing)['items'][0]['subtitle'])->toContain('ditolak Finance: Nominal transfer kurang');
});

test('the invoice list filters what awaits proof and opens the dialog for ?proof', function () {
    $mine = proofIssued($this);
    proofIssued($this, Lead::factory()->create(['assigned_to' => $this->colleague->id]));

    $this->actingAs($this->marketing)->get(route('finance.invoices.index', ['awaiting_proof' => 1, 'proof' => $mine->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('invoices.data', 1)
            ->where('invoices.data.0.id', $mine->id)
            ->where('proofInvoice.id', $mine->id));

    app(InvoiceService::class)->submitProof($mine, ['payment_proof_url' => 'https://drive.google.com/bukti'], $this->marketing);

    // Already sent: nothing to reopen.
    $this->actingAs($this->marketing)->get(route('finance.invoices.index', ['proof' => $mine->id]))
        ->assertInertia(fn (Assert $page) => $page->where('proofInvoice', null));
});

test('the RAB page offers "Kirim Bukti Bayar" to Marketing and Finance only', function (string $role, bool $can) {
    $invoice = proofIssued($this);

    $this->actingAs(proofUser($role))->get(route('quotations.show', $invoice->quotation_id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canSubmitProof', $can)
            ->where('invoices.0.status', 'DITERBITKAN')
            ->has('invoices.0.reject_reason'));
})->with([['MARKETING', true], ['FINANCE', true], ['CEO', false]]);

test('sending the proof from the RAB page works like from the Invoice menu', function () {
    $invoice = proofIssued($this);

    $this->actingAs($this->marketing)->post(route('finance.invoices.proof', $invoice), ['payment_proof_url' => 'https://drive.google.com/bukti'])
        ->assertSessionHasNoErrors();

    expect($invoice->fresh()->status->value)->toBe('MENUNGGU_VERIFIKASI');
});
