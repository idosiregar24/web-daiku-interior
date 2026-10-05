<?php

namespace App\Services;

use App\Enums\FinanceTransactionType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentTermTrigger;
use App\Enums\QuotationStatus;
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
    ) {}

    /**
     * The one invoice of a Jasa Survey / Jasa Desain RAB the client
     * approved — the full total, due on `due_date`.
     *
     * @param  array{due_date: string}  $data
     */
    public function issueForQuotation(Quotation $quotation, array $data, User $actor): Invoice
    {
        $type = InvoiceType::forQuotation($quotation->type);

        if ($type === null) {
            throw ValidationException::withMessages(['quotation' => 'Invoice RAB Proyek (DP / termin) diterbitkan dari termin proyek setelah proyek dibuka.']);
        }

        return DB::transaction(function () use ($quotation, $type, $data, $actor) {
            $quotation = Quotation::whereKey($quotation->getKey())->lockForUpdate()->firstOrFail();

            if ($quotation->status !== QuotationStatus::ClientApproved) {
                throw ValidationException::withMessages(['quotation' => 'Invoice hanya bisa diterbitkan setelah klien menyetujui RAB.']);
            }

            if (Invoice::where('quotation_id', $quotation->id)->where('type', $type->value)->exists()) {
                throw ValidationException::withMessages(['quotation' => "Invoice {$type->label()} untuk RAB ini sudah diterbitkan."]);
            }

            $invoice = Invoice::create([
                'number' => $this->nextNumber(),
                'lead_id' => $quotation->lead_id,
                'quotation_id' => $quotation->id,
                'type' => $type->value,
                'amount' => $quotation->total_amount,
                'due_date' => $data['due_date'],
                'status' => InvoiceStatus::Diterbitkan->value,
                'issued_by' => $actor->id,
                'issued_at' => now(),
            ]);

            $this->auditLogService->record('finance.invoice_issued', $invoice, null, $invoice->only(['number', 'type', 'amount', 'due_date', 'quotation_id', 'lead_id']), $actor);

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

            return $invoice;
        });
    }

    /** The client's transfer proof (a link) — the invoice goes to Finance's queue. */
    public function submitProof(Invoice $invoice, string $url, User $actor): Invoice
    {
        return DB::transaction(function () use ($invoice, $url, $actor) {
            $invoice = $this->locked($invoice, InvoiceStatus::Diterbitkan, 'Bukti bayar hanya bisa dikirim untuk invoice yang belum diverifikasi.');

            $invoice->update([
                'status' => InvoiceStatus::MenungguVerifikasi->value,
                'payment_proof_url' => trim($url),
                'proof_submitted_by' => $actor->id,
                'proof_submitted_at' => now(),
            ]);

            $this->auditLogService->record('finance.invoice_proof_submitted', $invoice, ['status' => InvoiceStatus::Diterbitkan], ['status' => $invoice->status, 'payment_proof_url' => $invoice->payment_proof_url], $actor);

            $this->notificationService->notifyRoles(
                ['FINANCE'],
                'invoice_awaiting_verification',
                'Pembayaran Menunggu Verifikasi',
                "Invoice {$invoice->number} ({$invoice->type->label()} \"{$invoice->lead->client_name}\", ".$this->rupiah($invoice->amount).') sudah dibayar — mohon verifikasi.',
                ['invoice_id' => $invoice->id],
            );

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
                'invoice_verified',
                'Pembayaran Terverifikasi',
                "Pembayaran invoice {$invoice->number} ({$invoice->type->label()} \"{$invoice->lead->client_name}\") sudah diverifikasi Finance.",
                ['invoice_id' => $invoice->id, 'lead_id' => $invoice->lead_id],
            );

            InvoiceVerified::dispatch($invoice);

            return $invoice->fresh();
        });
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
                'invoice_rejected',
                'Bukti Bayar Ditolak',
                "Finance menolak bukti bayar invoice {$invoice->number} (\"{$invoice->lead->client_name}\"): ".trim($reason),
                ['invoice_id' => $invoice->id],
            );

            return $invoice->fresh();
        });
    }

    /** INV-YYYYMM-0001, sequential per month (read under a lock; `number` is UNIQUE). */
    private function nextNumber(): string
    {
        $prefix = 'INV-'.now('Asia/Jakarta')->format('Ym').'-';
        $last = Invoice::where('number', 'like', $prefix.'%')->lockForUpdate()->max('number');
        $sequence = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
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
