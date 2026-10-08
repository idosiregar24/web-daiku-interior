<?php

namespace App\Services;

use App\Enums\FinanceTransactionType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\NotificationType;
use App\Enums\PaymentTermTrigger;
use App\Enums\QuotationStatus;
use App\Enums\QuotationType;
use App\Enums\TerminStatus;
use App\Events\InvoiceVerified;
use App\Models\BankAccount;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Termin;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 12 decisions #20–#21 — Marketing issues every invoice, Finance
 * verifies the payment:
 *
 *   issue (Marketing) → DITERBITKAN → submitProof (Marketing/Finance)
 *   → MENUNGGU_VERIFIKASI → verify (Finance) → TERVERIFIKASI
 *                         ↘ reject (Finance, reason) → DITERBITKAN
 *
 * Verifying books exactly one PEMASUKAN FinanceTransaction (through
 * FinanceTransactionService, so the account balance and audit follow) and
 * fires InvoiceVerified for the next step. Issued from an approved
 * service RAB (Jasa Survey / Jasa Desain, 100%, D4 — issueForQuotation())
 * or from a project termin (DP / termin / pelunasan — issueForTermin()).
 */
class InvoiceService
{
    public function __construct(
        private FinanceTransactionService $financeTransactionService,
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
        private LetterNumberService $letterNumbers,
    ) {}

    /**
     * What `quotations.invoices.store` would bill for this RAB right now —
     * `['label' => …, 'amount' => …]` — or null when nothing can be issued
     * from the RAB itself (the Quotation page's "Terbitkan Invoice").
     *
     * @return array{label: string, amount: string}|null
     */
    public function issuableFor(Quotation $quotation): ?array
    {
        if ($quotation->status !== QuotationStatus::ClientApproved || $quotation->invoices()->exists()) {
            return null;
        }

        if (($type = InvoiceType::forQuotation($quotation->type)) !== null) {
            return ['label' => $type->label(), 'amount' => (string) $quotation->total_amount];
        }

        $term = Quotation::query()->whereKey($quotation->id)->billableUpfront()->exists()
            ? $quotation->loadMissing('paymentTerms')->upfrontTerm()
            : null;

        if ($term === null) {
            return null;
        }

        // "DP", or "DP — Uang Muka" when the scheme row has its own name.
        $label = preg_match('/^dp\b/i', (string) $term->label) ? InvoiceType::Dp->label() : InvoiceType::Dp->label().' — '.$term->label;

        return ['label' => $label, 'amount' => (string) $term->amount];
    }

    /**
     * The one invoice of a Jasa Survey / Jasa Desain RAB the client
     * approved — the full total, due on `due_date`.
     * Sprint 17 Sub 06 (K2): a main RAB Proyek bills its DP ("di muka" row
     * of the scheme) here too, right after the client's approval — before
     * the CEO opens the project. Opening attaches it to the DP termin
     * (TerminService::attachUpfrontInvoice()), so it's never billed twice.
     *
     * @param  array{due_date: string}  $data
     */
    public function issueForQuotation(Quotation $quotation, array $data, User $actor): Invoice
    {
        $type = InvoiceType::forQuotation($quotation->type)
            ?? ($quotation->type === QuotationType::Proyek ? InvoiceType::Dp : null);

        if ($type === null) {
            throw ValidationException::withMessages(['quotation' => 'RAB ini tidak ditagih lewat invoice.']);
        }

        return DB::transaction(function () use ($quotation, $type, $data, $actor) {
            $quotation = Quotation::whereKey($quotation->getKey())->lockForUpdate()->firstOrFail();

            if ($quotation->status !== QuotationStatus::ClientApproved) {
                throw ValidationException::withMessages(['quotation' => 'Invoice hanya bisa diterbitkan setelah klien menyetujui RAB.']);
            }

            if (Invoice::where('quotation_id', $quotation->id)->where('type', $type->value)->exists()) {
                throw ValidationException::withMessages(['quotation' => "Invoice {$type->label()} untuk RAB ini sudah diterbitkan."]);
            }

            $amount = $quotation->total_amount;

            if ($type === InvoiceType::Dp) {
                if (! Quotation::query()->whereKey($quotation->id)->billableUpfront()->exists()) {
                    throw ValidationException::withMessages(['quotation' => $quotation->parent_quotation_id !== null || $quotation->openedProject()->exists()
                        ? 'Proyek sudah dibuka — terbitkan invoice dari termin proyek (tab Finance).'
                        : 'Skema pembayaran RAB ini tidak punya pembayaran di muka — DP ditagih dari termin setelah proyek dibuka.']);
                }

                $amount = $quotation->load('paymentTerms')->upfrontTerm()->amount;
            }

            $invoice = Invoice::create([
                'number' => $this->nextNumber(),
                'lead_id' => $quotation->lead_id,
                'quotation_id' => $quotation->id,
                'type' => $type->value,
                'amount' => $amount,
                'due_date' => $data['due_date'],
                'status' => InvoiceStatus::Diterbitkan->value,
                'issued_by' => $actor->id,
                'issued_at' => now(),
            ]);

            $this->auditLogService->record('finance.invoice_issued', $invoice, null, $invoice->only(['number', 'type', 'amount', 'due_date', 'quotation_id', 'lead_id']), $actor);

            // Sprint 17 Sub 03 — the RAB leaves the "terbitkan invoice" queue (the issuer's own
            // cache is dropped by ForgetActionInbox; the lead's Marketing may be someone else).
            ActionInboxService::forgetMarketingOf($quotation->lead?->assignee);

            return $invoice;
        });
    }

    /**
     * Sprint 12 #20 — a project termin billed by Marketing: what is still
     * owed on it, typed DP (DI_MUKA row), PELUNASAN (completion row, or the
     * last termin) or TERMIN. One invoice per termin; the termin becomes
     * INVOICED and is paid only through this invoice's verification.
     *
     * @param  array{due_date: string}  $data
     */
    public function issueForTermin(Termin $termin, array $data, User $actor): Invoice
    {
        return DB::transaction(function () use ($termin, $data, $actor) {
            $termin = Termin::whereKey($termin->getKey())->lockForUpdate()->with('project')->firstOrFail();

            if ($termin->status === TerminStatus::Paid || (float) $termin->sisa_piutang <= 0) {
                throw ValidationException::withMessages(['termin' => 'Termin ini sudah lunas.']);
            }

            if ($termin->invoice_id !== null) {
                throw ValidationException::withMessages(['termin' => 'Invoice termin ini sudah diterbitkan.']);
            }

            // "Last" within its own RAB — an addendum's termins come after the RAB Fix's.
            $isLast = $termin->termin_number >= (int) Termin::where('project_id', $termin->project_id)
                ->where('quotation_id', $termin->quotation_id)
                ->max('termin_number');
            $isAddendum = $termin->quotation_id !== null && $termin->quotation_id !== $termin->project->quotation_id;
            $type = match (true) {
                // Sprint 12 #29 / D7 — every termin of a RAB Tambahan.
                $isAddendum => InvoiceType::Tambahan,
                $termin->trigger === PaymentTermTrigger::DiMuka => InvoiceType::Dp,
                $termin->trigger === PaymentTermTrigger::ProyekSelesai, $isLast => InvoiceType::Pelunasan,
                default => InvoiceType::Termin,
            };

            $invoice = Invoice::create([
                'number' => $this->nextNumber(),
                'lead_id' => $termin->project->lead_id,
                'project_id' => $termin->project_id,
                'quotation_id' => $termin->quotation_id ?? $termin->project->quotation_id,
                'termin_id' => $termin->id,
                'type' => $type->value,
                'amount' => $termin->sisa_piutang,
                'due_date' => $data['due_date'],
                'status' => InvoiceStatus::Diterbitkan->value,
                'issued_by' => $actor->id,
                'issued_at' => now(),
            ]);

            $termin->update(array_filter([
                'invoice_id' => $invoice->id,
                'status' => $termin->status === TerminStatus::Scheduled ? TerminStatus::Invoiced->value : null,
            ]));

            $this->auditLogService->record('finance.invoice_issued', $invoice, null, $invoice->only(['number', 'type', 'amount', 'due_date', 'termin_id', 'project_id']), $actor);

            ActionInboxService::forgetMarketingOf($termin->project->lead?->assignee);

            return $invoice;
        });
    }

    /**
     * "Tandai Klien Sudah Bayar" — the invoice goes to Finance's queue.
     * Sprint 19 Sub 01: the transfer proof link is optional (a client may
     * only phone in the payment) and a short note may come with it; Finance
     * still matches the money against the bank statement before verify().
     *
     * @param  array{payment_proof_url?: ?string, payment_note?: ?string}  $data
     */
    public function submitProof(Invoice $invoice, array $data, User $actor): Invoice
    {
        $url = trim((string) ($data['payment_proof_url'] ?? '')) ?: null;
        $note = trim((string) ($data['payment_note'] ?? '')) ?: null;

        return DB::transaction(function () use ($invoice, $url, $note, $actor) {
            $invoice = $this->locked($invoice, InvoiceStatus::Diterbitkan, 'Pembayaran hanya bisa ditandai untuk invoice yang belum diverifikasi.');

            $invoice->update([
                'status' => InvoiceStatus::MenungguVerifikasi->value,
                'payment_proof_url' => $url,
                'payment_note' => $note,
                'proof_submitted_by' => $actor->id,
                'proof_submitted_at' => now(),
            ]);

            $this->auditLogService->record('finance.invoice_proof_submitted', $invoice, ['status' => InvoiceStatus::Diterbitkan], ['status' => $invoice->status, 'payment_proof_url' => $url, 'payment_note' => $note], $actor);

            $message = "Invoice {$invoice->number} ({$invoice->type->label()} \"{$invoice->lead->client_name}\", ".$this->rupiah($invoice->amount).') sudah dibayar — mohon verifikasi.';
            if ($url === null) {
                $message .= ' Tanpa link bukti — cocokkan dengan mutasi rekening.';
            }
            if ($note !== null) {
                $message .= " Catatan: {$note}";
            }

            $this->notificationService->notifyRoles(
                ['FINANCE'],
                NotificationType::InvoiceAwaitingVerification,
                'Pembayaran Menunggu Verifikasi',
                $message,
                ['invoice_id' => $invoice->id],
            );

            // Sprint 17 Sub 07 — Finance may send it for the lead's Marketing.
            ActionInboxService::forgetMarketingOf($invoice->lead->assignee);

            return $invoice->fresh();
        });
    }

    /**
     * Finance confirms the money arrived on `bank_account_id` on
     * `paid_date`: one PEMASUKAN transaction (never twice — the row lock
     * plus the status check, and `finance_transaction_id` is UNIQUE), then
     * InvoiceVerified.
     *
     * @param  array{bank_account_id: int|string, paid_date: string}  $data
     */
    public function verify(Invoice $invoice, array $data, User $actor): Invoice
    {
        if (! BankAccount::whereKey($data['bank_account_id'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['bank_account_id' => 'Rekening penerima wajib dipilih dan harus aktif.']);
        }

        return DB::transaction(function () use ($invoice, $data, $actor) {
            $invoice = $this->locked($invoice, InvoiceStatus::MenungguVerifikasi, 'Invoice ini tidak sedang menunggu verifikasi.');

            $transaction = $this->financeTransactionService->create([
                'project_id' => $invoice->project_id,
                'bank_account_id' => $data['bank_account_id'],
                'type' => FinanceTransactionType::Income->value,
                'kategori' => $invoice->type->financeCategory()->value,
                'amount' => $invoice->amount,
                'description' => "Pembayaran invoice {$invoice->number} — {$invoice->type->label()} {$invoice->lead->client_name}",
                'reference_id' => $invoice->id,
                'date' => $data['paid_date'],
            ], $actor, 'finance.invoice_payment');

            $invoice->update([
                'status' => InvoiceStatus::Terverifikasi->value,
                'bank_account_id' => $data['bank_account_id'],
                'paid_date' => $data['paid_date'],
                'verified_by' => $actor->id,
                'verified_at' => now(),
                'finance_transaction_id' => $transaction->id,
            ]);

            $this->auditLogService->record('finance.invoice_verified', $invoice, ['status' => InvoiceStatus::MenungguVerifikasi], [
                'status' => $invoice->status,
                'amount' => $invoice->amount,
                'bank_account_id' => $invoice->bank_account_id,
                'paid_date' => $data['paid_date'],
                'finance_transaction_id' => $transaction->id,
            ], $actor);

            $this->notificationService->notifyMany(
                collect([$invoice->issuer, $invoice->lead->assignee])->filter()->unique('id'),
                NotificationType::InvoiceVerified,
                'Pembayaran Terverifikasi',
                "Pembayaran invoice {$invoice->number} ({$invoice->type->label()} \"{$invoice->lead->client_name}\") sudah diverifikasi Finance.".self::nextStepAfterPayment($invoice),
                ['invoice_id' => $invoice->id, 'lead_id' => $invoice->lead_id],
            );

            InvoiceVerified::dispatch($invoice);

            return $invoice->fresh();
        });
    }

    /** Sprint 19 — what Marketing does next, in the "Pembayaran Terverifikasi" message. */
    private static function nextStepAfterPayment(Invoice $invoice): string
    {
        return match ($invoice->type) {
            InvoiceType::JasaSurvey => ' Survey bisa berangkat — pastikan jadwalnya sudah dibuat.',
            InvoiceType::JasaDesain => ' Desain dibuka untuk ditugaskan Kepala Desain.',
            InvoiceType::Dp => $invoice->project_id === null ? ' Menunggu CEO membuka proyek.' : '',
            default => '',
        };
    }

    /** The proof doesn't match a real payment — back to DITERBITKAN, Marketing is told why. */
    public function reject(Invoice $invoice, string $reason, User $actor): Invoice
    {
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Alasan penolakan wajib diisi.']);
        }

        return DB::transaction(function () use ($invoice, $reason, $actor) {
            $invoice = $this->locked($invoice, InvoiceStatus::MenungguVerifikasi, 'Invoice ini tidak sedang menunggu verifikasi.');

            $invoice->update([
                'status' => InvoiceStatus::Diterbitkan->value,
                'reject_reason' => trim($reason),
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
            ]);

            $this->auditLogService->record('finance.invoice_rejected', $invoice, ['status' => InvoiceStatus::MenungguVerifikasi, 'payment_proof_url' => $invoice->payment_proof_url], ['status' => $invoice->status, 'reason' => trim($reason)], $actor);

            $invoice->loadMissing(['issuer', 'lead.assignee']);
            $this->notificationService->notifyMany(
                collect([$invoice->issuer, $invoice->lead->assignee, User::find($invoice->proof_submitted_by)])->filter()->unique('id'),
                NotificationType::InvoiceRejected,
                'Bukti Bayar Ditolak',
                "Finance menolak bukti bayar invoice {$invoice->number} (\"{$invoice->lead->client_name}\"): ".trim($reason),
                ['invoice_id' => $invoice->id],
            );

            // Sprint 17 Sub 07 — back in the lead Marketing's "Invoice menunggu bukti bayar".
            ActionInboxService::forgetMarketingOf($invoice->lead->assignee);

            return $invoice->fresh();
        });
    }

    /**
     * Sprint 15 K2 — "378/INV/Daiku/IX/2026", from the letter numbers shared
     * with offers (LetterNumberService). Invoices issued before keep their
     * old "INV-YYYYMM-NNNN" number.
     */
    private function nextNumber(): string
    {
        return $this->letterNumbers->next(LetterNumberService::INVOICE);
    }

    private function locked(Invoice $invoice, InvoiceStatus $required, string $message): Invoice
    {
        $invoice = Invoice::whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

        if ($invoice->status !== $required) {
            throw ValidationException::withMessages(['status' => $message]);
        }

        return $invoice->load(['lead.assignee', 'issuer']);
    }

    private function rupiah(string|float|null $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }
}
