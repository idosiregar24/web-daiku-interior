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
use App\Models\InvoicePaymentProof;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\Termin;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

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
        private InvoicePaymentProofService $proofPhotos,
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

            // DP / Termin / Pelunasan ("last" within its own RAB) / Tambahan — Termin::invoiceType().
            $type = $termin->invoiceType();

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
     * Sprint 23 K12d: up to 3 proof photos may come with it too — encoded
     * before the transaction (InvoicePaymentProofService), recorded under
     * this submission's `proof_attempt`; a submission after Finance's
     * rejection is the next attempt and the earlier photos stay.
     *
     * @param  array{payment_proof_url?: ?string, payment_note?: ?string, payment_photos?: list<UploadedFile>}  $data
     */
    public function submitProof(Invoice $invoice, array $data, User $actor): Invoice
    {
        $url = trim((string) ($data['payment_proof_url'] ?? '')) ?: null;
        $note = trim((string) ($data['payment_note'] ?? '')) ?: null;
        $photos = array_values(array_filter($data['payment_photos'] ?? [], fn ($file) => $file instanceof UploadedFile));

        if (count($photos) > InvoicePaymentProof::MAX_PHOTOS) {
            throw ValidationException::withMessages(['payment_photos' => 'Maksimal '.InvoicePaymentProof::MAX_PHOTOS.' foto bukti bayar.']);
        }

        $encoded = $this->proofPhotos->encode($photos);
        $written = [];

        try {
            return DB::transaction(function () use ($invoice, $url, $note, $encoded, $actor, &$written) {
                $invoice = $this->locked($invoice, InvoiceStatus::Diterbitkan, 'Pembayaran hanya bisa ditandai untuk invoice yang belum diverifikasi.');
                $attempt = $invoice->proof_attempt + 1;

                $invoice->update([
                    'status' => InvoiceStatus::MenungguVerifikasi->value,
                    'payment_proof_url' => $url,
                    'payment_note' => $note,
                    'proof_attempt' => $attempt,
                    'proof_submitted_by' => $actor->id,
                    'proof_submitted_at' => now(),
                ]);

                $proofs = $this->proofPhotos->store($invoice, $encoded, $attempt, $actor, $written);

                $this->auditLogService->record('finance.invoice_proof_submitted', $invoice, ['status' => InvoiceStatus::Diterbitkan], [
                    'status' => $invoice->status,
                    'payment_proof_url' => $url,
                    'payment_note' => $note,
                    'attempt' => $attempt,
                    'photo_ids' => $proofs->pluck('id')->all(),
                ], $actor);

                $message = "Invoice {$invoice->number} ({$invoice->paymentTypeLabel()} \"{$invoice->lead->client_name}\", ".$this->rupiah($invoice->amount).') sudah dibayar — mohon verifikasi.';
                if ($url === null && $proofs->isEmpty()) {
                    $message .= ' Tanpa link bukti — cocokkan dengan mutasi rekening.';
                }
                if ($proofs->isNotEmpty()) {
                    $message .= " Dilampiri {$proofs->count()} foto bukti bayar.";
                }
                if ($note !== null) {
                    $message .= " Catatan: {$note}";
                }

                // Sprint 23 K12b — the title says which payment ("Menunggu Konfirmasi Pembayaran DP · Proyek Kopi Oz").
                $this->notificationService->notifyRoles(
                    ['FINANCE'],
                    NotificationType::InvoiceAwaitingVerification,
                    $invoice->confirmationLabel(),
                    $message,
                    ['invoice_id' => $invoice->id],
                );

                // Sprint 17 Sub 07 — Finance may send it for the lead's Marketing.
                ActionInboxService::forgetMarketingOf($invoice->lead->assignee);

                return $invoice->fresh();
            });
        } catch (Throwable $e) {
            $this->proofPhotos->deleteOrphans($written);

            throw $e;
        }
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
            // Sprint 23 K13 — the PM who approved the RAB Proyek opens it.
            InvoiceType::Dp => $invoice->project_id === null ? ' Menunggu PM membuka proyek.' : '',
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
     * Sprint 23 K12c — "Informasi Pembayaran" of a client (lead detail; meant
     * to be reused on Detail Proyek): every payment of the lead, in the order
     * the client meets them — Jasa Survey, Jasa Desain, the project's DP /
     * termins / pelunasan, then Pekerjaan Tambahan — plus a summary.
     *
     * Rows: every invoice of the lead; every project termin without an
     * invoice ("Belum ditagih", or "Lunas" when Finance recorded it by
     * hand); and what an approved RAB still has to bill (issuableFor()).
     * `state` → whose turn: BELUM_DITAGIH · Marketing, BELUM_DIBAYAR ·
     * Klien, MENUNGGU_KONFIRMASI · Finance, LUNAS. The chip is sent as
     * `domain` + `status` for the frontend's StatusChip (lib/waitingOn.ts).
     *
     * PDF links follow the viewer's routes: `finance.invoices.pdf` for
     * CEO/Marketing/Finance, else a termin's own bill (`finance.termins.pdf`,
     * CEO/PM/Finance) — never a link that would answer 403.
     *
     * @return array{summary: array<string, int|float>, items: list<array<string, mixed>>}
     */
    public function leadPayments(Lead $lead, User $viewer): array
    {
        $today = now('Asia/Jakarta')->toDateString();
        $invoicePdf = $viewer->hasAnyRole(['CEO', 'MARKETING', 'FINANCE', 'SUPERADMIN']);
        $terminPdf = $viewer->hasAnyRole(['CEO', 'PM', 'FINANCE', 'SUPERADMIN']);
        $items = collect();

        $invoices = Invoice::query()
            ->where('lead_id', $lead->id)
            ->withPaymentLabelRelations()
            ->with(['bankAccount:id,label', 'quotation:id,type,custom_name,parent_quotation_id'])
            ->get();

        foreach ($invoices as $invoice) {
            $state = match ($invoice->status) {
                InvoiceStatus::Diterbitkan => 'BELUM_DIBAYAR',
                InvoiceStatus::MenungguVerifikasi => 'MENUNGGU_KONFIRMASI',
                InvoiceStatus::Terverifikasi => 'LUNAS',
            };

            $items->push([
                'key' => "invoice-{$invoice->id}",
                'kind' => $invoice->type->value,
                'label' => $invoice->paymentTypeLabel(),
                'context' => $invoice->project?->name ?? $invoice->quotation?->title(),
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'amount' => (float) $invoice->amount,
                'paid_amount' => $state === 'LUNAS' ? (float) $invoice->amount : 0.0,
                'due_date' => $invoice->due_date?->toDateString(),
                'schedule_note' => null,
                'state' => $state,
                'domain' => 'invoice',
                'status' => $invoice->status->value,
                'overdue' => $state === 'BELUM_DIBAYAR' && $invoice->due_date !== null && $invoice->due_date->toDateString() < $today,
                'paid_date' => $invoice->paid_date?->toDateString(),
                'bank_account' => $invoice->bankAccount?->label,
                'reject_reason' => $state === 'BELUM_DIBAYAR' ? $invoice->reject_reason : null,
                'pdf_url' => match (true) {
                    $invoicePdf => route('finance.invoices.pdf', $invoice),
                    $terminPdf && $invoice->termin_id !== null => route('finance.termins.pdf', $invoice->termin_id),
                    default => null,
                },
                'sort' => $this->paymentSortKey($invoice->type, $invoice->termin?->termin_number, $invoice->issued_at?->timestamp),
            ]);
        }

        $termins = Termin::query()
            ->whereHas('project', fn ($project) => $project->where('lead_id', $lead->id))
            ->whereNull('invoice_id')
            ->withRabPosition()
            ->with(['project:id,name,quotation_id', 'bankAccount:id,label'])
            ->get();

        foreach ($termins as $termin) {
            $paid = $termin->status === TerminStatus::Paid;
            $type = $termin->invoiceType();

            $items->push([
                'key' => "termin-{$termin->id}",
                'kind' => $type->value,
                'label' => $termin->paymentLabel($type),
                'context' => $termin->project?->name,
                'invoice_id' => null,
                'invoice_number' => null,
                'amount' => (float) $termin->amount,
                // Finance may have recorded part of it by hand (TerminService::recordPayment()).
                'paid_amount' => $paid ? (float) $termin->amount : (float) $termin->dp_amount + (float) $termin->pelunasan,
                'due_date' => $termin->scheduled_date?->toDateString(),
                'schedule_note' => $termin->scheduled_date === null ? $this->terminTriggerNote($termin) : null,
                'state' => $paid ? 'LUNAS' : 'BELUM_DITAGIH',
                'domain' => 'termin',
                'status' => $paid ? TerminStatus::Paid->value : TerminStatus::Scheduled->value,
                'overdue' => ! $paid && ($termin->status === TerminStatus::Overdue || ($termin->scheduled_date !== null && $termin->scheduled_date->toDateString() < $today)),
                'paid_date' => $termin->paid_at?->toDateString(),
                'bank_account' => $paid ? $termin->bankAccount?->label : null,
                'reject_reason' => null,
                'pdf_url' => $terminPdf ? route('finance.termins.pdf', $termin) : null,
                'sort' => $this->paymentSortKey($type, $termin->termin_number, $termin->created_at?->timestamp),
            ]);
        }

        // An approved RAB with nothing issued yet (Jasa Survey / Jasa Desain, a RAB Proyek's upfront DP).
        $lead->quotations()->where('status', QuotationStatus::ClientApproved->value)->get()
            ->each(function (Quotation $quotation) use ($items) {
                if (($issuable = $this->issuableFor($quotation)) === null) {
                    return;
                }

                $type = InvoiceType::forQuotation($quotation->type) ?? InvoiceType::Dp;

                $items->push([
                    'key' => "quotation-{$quotation->id}",
                    'kind' => $type->value,
                    'label' => $issuable['label'],
                    'context' => $quotation->title(),
                    'invoice_id' => null,
                    'invoice_number' => null,
                    'amount' => (float) $issuable['amount'],
                    'paid_amount' => 0.0,
                    'due_date' => null,
                    'schedule_note' => 'Setelah klien menyetujui RAB',
                    'state' => 'BELUM_DITAGIH',
                    'domain' => 'termin',
                    'status' => TerminStatus::Scheduled->value,
                    'overdue' => false,
                    'paid_date' => null,
                    'bank_account' => null,
                    'reject_reason' => null,
                    'pdf_url' => null,
                    'sort' => $this->paymentSortKey($type, 0, $quotation->client_approved_at?->timestamp),
                ]);
            });

        $items = $items->sortBy('sort')->values()->map(fn (array $item) => Arr::except($item, 'sort'));

        $cents = fn (float $value) => (int) round($value * 100);
        $sum = fn (iterable $rows, callable $value) => collect($rows)->sum(fn (array $row) => $cents($value($row)));
        $total = $sum($items, fn ($row) => $row['amount']);
        $paid = $sum($items, fn ($row) => $row['paid_amount']);
        $awaiting = $sum($items->where('state', 'MENUNGGU_KONFIRMASI'), fn ($row) => $row['amount']);
        $overdueRows = $items->where('overdue', true);

        return [
            'summary' => [
                'total' => $total / 100,
                'paid' => $paid / 100,
                'awaiting_confirmation' => $awaiting / 100,
                'unpaid' => ($total - $paid - $awaiting) / 100,
                'not_billed' => $sum($items->where('state', 'BELUM_DITAGIH'), fn ($row) => $row['amount'] - $row['paid_amount']) / 100,
                'overdue' => $sum($overdueRows, fn ($row) => $row['amount'] - $row['paid_amount']) / 100,
                'overdue_count' => $overdueRows->count(),
                'count' => $items->count(),
            ],
            'items' => $items->all(),
        ];
    }

    /** Survey → Desain → the project's own scheme (DP first) → Pekerjaan Tambahan; then by number / date. */
    private function paymentSortKey(InvoiceType $type, ?int $terminNumber, ?int $timestamp): string
    {
        $group = match ($type) {
            InvoiceType::JasaSurvey => 1,
            InvoiceType::JasaDesain => 2,
            InvoiceType::Tambahan => 4,
            default => 3,
        };

        return sprintf('%d-%05d-%012d', $group, $terminNumber ?? 0, $timestamp ?? 0);
    }

    /** A termin without a date yet — what makes it due (Sprint 12 #12; K16 "Timeline"). */
    private function terminTriggerNote(Termin $termin): ?string
    {
        return match ($termin->trigger) {
            PaymentTermTrigger::Milestone => $termin->milestone_name ? "Setelah timeline \"{$termin->milestone_name}\" selesai" : 'Setelah timeline selesai',
            PaymentTermTrigger::ProyekSelesai => 'Setelah proyek selesai',
            PaymentTermTrigger::DiMuka => 'Di muka',
            default => null,
        };
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

        return $invoice->load(['lead.assignee', 'issuer', 'project:id,name', 'termin']);
    }

    private function rupiah(string|float|null $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }
}
