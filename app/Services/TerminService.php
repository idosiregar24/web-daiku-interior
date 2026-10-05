<?php

namespace App\Services;

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Enums\InvoiceType;
use App\Enums\MilestoneStatus;
use App\Enums\PaymentTermTrigger;
use App\Enums\ProjectStatus;
use App\Enums\TerminStatus;
use App\Models\BankAccount;
use App\Models\FinanceTransaction;
use App\Models\Invoice;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\QuotationPaymentTerm;
use App\Models\Termin;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PRD §4.4/§4.7/§6.4 "Termin Schedule" / "Logika Termin Sabtu". PM
 * schedules (`create`), Finance records DP/pelunasan (`recordPayment`,
 * `markPaid` = pay the full remainder) — matches PRD
 * §7.1 "Finance – Termin" row (PM `C` only, Finance `RU`).
 */
class TerminService
{
    public function __construct(
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
    ) {}

    /**
     * Legacy (pre-Sprint-12) manual scheduling — no route since Sprint 12
     * Sub 7 (termins come from the approved scheme, createFromPaymentTerms());
     * kept for projects created before and the demo data.
     *
     * PRD §6.4: "PM membuat termin: bebas tentukan persentase. Validasi:
     * total semua persentase termin = 100%. scheduledDate otomatis =
     * Sabtu terdekat setelah milestone.targetDate" — enforced as a
     * ceiling here (a project's termins may sum to less than 100% while
     * PM is still scheduling the rest, but never more).
     */
    public function create(Project $project, array $data): Termin
    {
        $existingTotal = (float) $project->termins()->sum('percentage');

        if (round(($existingTotal + (float) $data['percentage']) * 100) > 10000) {
            throw ValidationException::withMessages([
                'percentage' => "Total persentase termin proyek ini sudah {$existingTotal}% — tidak boleh melebihi 100%.",
            ]);
        }

        $milestone = isset($data['milestone_id']) ? Milestone::find($data['milestone_id']) : null;
        $baseDate = $milestone?->target_date ?? now();

        $terminNumber = (int) $project->termins()->max('termin_number') + 1;

        return $project->termins()->create([
            'milestone_id' => $milestone?->id,
            'termin_number' => $terminNumber,
            'percentage' => $data['percentage'],
            'amount' => round($project->contract_value * $data['percentage'] / 100, 2),
            'scheduled_date' => $this->getNextSaturday(Carbon::parse($baseDate)),
            'status' => TerminStatus::Scheduled->value,
            'bank_account_id' => $data['bank_account_id'] ?? null,
        ]);
    }

    /**
     * Sprint 12 decision #12 — a project opened from an approved RAB gets
     * one termin per row of the payment scheme the client approved: same
     * percentage and amount (they already add up to the contract value),
     * trigger copied. Dates: DI_MUKA → the project's start date, TANGGAL →
     * its due date, MILESTONE / PROYEK_SELESAI → none until the work gets
     * there (TerminInvoiceReminderJob tells Marketing when to invoice).
     * The PRD's "always Saturday" rule stays with the legacy manual
     * termins (create()).
     *
     * @return Collection<int, Termin>
     */
    public function createFromPaymentTerms(Project $project, Quotation $quotation): Collection
    {
        return $quotation->paymentTerms->values()->map(fn (QuotationPaymentTerm $term) => $project->termins()->create([
            'payment_term_id' => $term->id,
            'trigger' => $term->trigger->value,
            'milestone_name' => $term->milestone_name,
            'termin_number' => $term->sequence,
            'percentage' => $term->percentage,
            'amount' => $term->amount,
            'scheduled_date' => match ($term->trigger) {
                PaymentTermTrigger::DiMuka => $project->start_date?->toDateString(),
                PaymentTermTrigger::Tanggal => $term->due_date?->toDateString(),
                default => null,
            },
            'status' => TerminStatus::Scheduled->value,
        ]));
    }

    /**
     * PRD §4.9 "Termin overdue → Finance, CEO" — daily (TerminOverdueJob).
     * An unpaid termin past its Saturday moves to OVERDUE; that status
     * change is also the idempotency guard (already-OVERDUE termins are
     * never re-picked, so never re-notified). OVERDUE stays payable —
     * recordPayment()/markPaid() only refuse PAID. A partially paid
     * termin (DP/pelunasan > 0, sisa_piutang > 0) keeps its SCHEDULED/
     * INVOICED status and so can still go OVERDUE; a fully paid one is
     * PAID and never picked.
     */
    public function markOverdue(): int
    {
        $overdue = Termin::query()
            ->with('project:id,name')
            ->whereIn('status', [TerminStatus::Scheduled->value, TerminStatus::Invoiced->value])
            ->whereDate('scheduled_date', '<', now('Asia/Jakarta')->toDateString())
            ->get();

        foreach ($overdue as $termin) {
            $termin->update(['status' => TerminStatus::Overdue->value]);

            $this->notificationService->notifyRoles(
                ['FINANCE', 'CEO'],
                'termin_overdue',
                'Termin Overdue',
                "Termin #{$termin->termin_number} proyek \"{$termin->project->name}\" (sisa piutang Rp "
                    .number_format((float) $termin->sisa_piutang, 0, ',', '.')
                    .") melewati jadwal {$termin->scheduled_date->translatedFormat('d F Y')} dan belum lunas.",
                ['termin_id' => $termin->id, 'project_id' => $termin->project_id],
            );
        }

        return $overdue->count();
    }

    /** PRD §6.4 pseudocode, ported 1:1 (0=Minggu…6=Sabtu, same as JS `Date.getDay()`). */
    public function getNextSaturday(Carbon $fromDate): Carbon
    {
        $dayOfWeek = $fromDate->dayOfWeek;
        $daysUntilSaturday = $dayOfWeek === Carbon::SATURDAY ? 7 : (Carbon::SATURDAY - $dayOfWeek);

        return $fromDate->copy()->addDays($daysUntilSaturday);
    }

    /**
     * Backward-compatible "Tandai Dibayar": pays the whole remaining
     * sisa_piutang as one PELUNASAN into the termin's own bank account —
     * same single code path as recordPayment().
     */
    public function markPaid(Termin $termin, User $actor): Termin
    {
        return $this->recordPayment($termin, [
            'type' => self::PAYMENT_PELUNASAN,
            'amount' => null, // null = seluruh sisa piutang (resolved under the row lock)
            'bank_account_id' => $termin->bank_account_id,
            'paid_date' => now()->toDateString(),
        ], $actor);
    }

    public const PAYMENT_DP = 'DP';

    public const PAYMENT_PELUNASAN = 'PELUNASAN';

    /**
     * PRD §4.7 "DP + pelunasan, sisa piutang otomatis terhitung" /
     * daiku_schema.sql `termins.dp_amount`/`pelunasan`/`sisa_piutang`.
     * One call = one payment = one income FinanceTransaction + one audit
     * row. When sisa_piutang reaches 0 the termin becomes PAID. Status
     * otherwise stays as-is — "Dibayar Sebagian" is a derived display
     * state (Termin::isPartiallyPaid()), not a DB status.
     *
     * PRD §6.3 "Termin Sabtu unlocked (Finance bisa generate invoice)"
     * once the linked milestone is COMPLETED — a termin not tied to a
     * specific milestone has no such gate. The gate applies to PELUNASAN
     * only: a DP (uang muka) is by definition received before the work,
     * so it can be taken while the milestone is still running (decided
     * 2026-09-28, Sprint 8). markPaid() is a pelunasan, so it stays gated.
     *
     * @param  array{type: string, amount: numeric-string|float|int|null, bank_account_id: int|string|null, paid_date: string}  $data
     */
    public function recordPayment(Termin $termin, array $data, User $actor): Termin
    {
        // PRD §4.7 "Setiap transaksi wajib mencantumkan rekening bank" —
        // enforced here, not only in RecordTerminPaymentRequest, because
        // markPaid() reaches this with the termin's own (nullable) account.
        $bankAccountId = $data['bank_account_id'] ?? null;

        if (! $bankAccountId || ! BankAccount::whereKey($bankAccountId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'bank_account_id' => 'Rekening penerima wajib dipilih dan harus aktif.',
            ]);
        }

        if ($data['type'] === self::PAYMENT_PELUNASAN
            && $termin->milestone_id
            && $termin->milestone->status !== MilestoneStatus::Completed) {
            throw ValidationException::withMessages([
                'status' => 'Pelunasan termin ini masih terkunci — milestone terkait belum COMPLETED (lolos QA). DP tetap bisa diterima.',
            ]);
        }

        return DB::transaction(function () use ($termin, $data, $actor) {
            /** @var Termin $locked */
            $locked = Termin::query()->with('project:id,name')->lockForUpdate()->findOrFail($termin->id);

            if ($locked->status === TerminStatus::Paid || $this->toCents($locked->sisa_piutang) <= 0) {
                throw ValidationException::withMessages([
                    'status' => 'Termin ini sudah dibayar lunas.',
                ]);
            }

            // Sprint 12 #21 — an invoiced termin is paid by verifying its
            // invoice (InvoiceService::verify() books the income), never
            // directly — that would count the money twice.
            if ($locked->invoice_id !== null) {
                throw ValidationException::withMessages([
                    'status' => 'Termin ini ditagih lewat invoice — verifikasi pembayarannya di menu Verifikasi Pembayaran.',
                ]);
            }

            $type = $data['type'];
            $sisaCents = $this->toCents($locked->sisa_piutang);
            $amountCents = $data['amount'] === null ? $sisaCents : $this->toCents($data['amount']);

            if ($amountCents <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Nominal pembayaran harus lebih dari 0.',
                ]);
            }

            if ($amountCents > $sisaCents) {
                throw ValidationException::withMessages([
                    'amount' => 'Nominal pembayaran melebihi sisa piutang (Rp '
                        .number_format($sisaCents / 100, 0, ',', '.').').',
                ]);
            }

            if ($type === self::PAYMENT_DP && $this->toCents($locked->pelunasan) > 0) {
                throw ValidationException::withMessages([
                    'type' => 'DP tidak bisa dicatat — termin ini sudah menerima pelunasan.',
                ]);
            }

            $old = [
                'status' => $locked->status,
                'dp_amount' => $locked->dp_amount,
                'pelunasan' => $locked->pelunasan,
                'sisa_piutang' => $locked->sisa_piutang,
            ];

            $column = $type === self::PAYMENT_DP ? 'dp_amount' : 'pelunasan';
            $locked->update([
                $column => ($this->toCents($locked->{$column}) + $amountCents) / 100,
            ]);

            // sisa_piutang is DB-generated — re-read it rather than recompute.
            $locked->refresh();

            if ($this->toCents($locked->sisa_piutang) === 0) {
                $locked->update(['status' => TerminStatus::Paid->value, 'paid_at' => now()]);
            }

            $bankAccountId = $data['bank_account_id'] ?? null;
            $amount = $amountCents / 100;
            $label = $type === self::PAYMENT_DP ? 'DP' : 'Pelunasan';

            FinanceTransaction::create([
                'project_id' => $locked->project_id,
                'bank_account_id' => $bankAccountId,
                'type' => FinanceTransactionType::Income->value,
                'kategori' => ($type === self::PAYMENT_DP ? FinanceCategory::DownPayment : FinanceCategory::Termin)->value,
                'amount' => $amount,
                'description' => "{$label} termin #{$locked->termin_number} — {$locked->project->name}",
                'reference_id' => $locked->id,
                'date' => $data['paid_date'],
                'created_by' => $actor->id,
            ]);

            // PRD §9.4 "perubahan finance" — every payment is audited.
            $this->auditLogService->record(
                'finance.termin_payment',
                $locked,
                $old,
                [
                    'type' => $type,
                    'amount' => $amount,
                    'bank_account_id' => $bankAccountId,
                    'paid_date' => $data['paid_date'],
                    'status' => $locked->status,
                    'dp_amount' => $locked->dp_amount,
                    'pelunasan' => $locked->pelunasan,
                    'sisa_piutang' => $locked->sisa_piutang,
                ],
                $actor,
            );

            // The status flip to PAID keeps its own audit code (pre-existing
            // `finance.termin_paid`, labelled in Pages/AuditLogs/Index.tsx).
            if ($locked->status === TerminStatus::Paid) {
                $this->auditLogService->record(
                    'finance.termin_paid',
                    $locked,
                    ['status' => $old['status']],
                    ['status' => $locked->status, 'amount' => $locked->amount, 'paid_at' => $locked->paid_at, 'bank_account_id' => $bankAccountId],
                    $actor,
                );
            }

            return $locked->fresh();
        });
    }

    /**
     * Sprint 12 #21 — Finance verified the termin's invoice
     * (SettleTerminOnInvoiceVerified): the amount goes onto the termin (a
     * DP invoice as DP, any other as pelunasan) and it becomes PAID when
     * nothing is left. No FinanceTransaction here — InvoiceService::verify()
     * already booked the income.
     */
    public function settleFromInvoice(Termin $termin, Invoice $invoice, User $actor): Termin
    {
        return DB::transaction(function () use ($termin, $invoice, $actor) {
            /** @var Termin $locked */
            $locked = Termin::query()->lockForUpdate()->findOrFail($termin->id);
            $amountCents = min($this->toCents($invoice->amount), $this->toCents($locked->sisa_piutang));

            if ($amountCents <= 0) {
                return $locked;
            }

            $old = $locked->only(['status', 'dp_amount', 'pelunasan', 'sisa_piutang']);
            $column = $invoice->type === InvoiceType::Dp ? 'dp_amount' : 'pelunasan';
            $locked->update([$column => ($this->toCents($locked->{$column}) + $amountCents) / 100]);
            $locked->refresh();

            if ($this->toCents($locked->sisa_piutang) === 0) {
                $locked->update(['status' => TerminStatus::Paid->value, 'paid_at' => now()]);
            }

            $this->auditLogService->record('finance.termin_payment', $locked, $old, [
                'invoice' => $invoice->number,
                'amount' => $amountCents / 100,
                'status' => $locked->status,
                'dp_amount' => $locked->dp_amount,
                'pelunasan' => $locked->pelunasan,
                'sisa_piutang' => $locked->sisa_piutang,
            ], $actor);

            return $locked->fresh();
        });
    }

    /**
     * Sprint 12 #20 — TerminInvoiceReminderJob, daily: a scheme termin
     * whose trigger has been reached and that has no invoice yet → the
     * lead's Marketing is told "Terbitkan invoice termin N". Reached =
     * DI_MUKA right away, TANGGAL on/after its date, MILESTONE once the
     * project's milestone of that name is COMPLETED (it gets linked then),
     * PROYEK_SELESAI once the project is COMPLETED. Once per termin
     * (`invoice_reminded_at`), so re-runs never notify twice.
     */
    public function remindInvoices(): int
    {
        $today = now('Asia/Jakarta')->toDateString();
        $sent = 0;

        $termins = Termin::query()
            ->with(['project.lead.assignee', 'project.milestones:id,project_id,name,status'])
            ->whereNotNull('trigger')
            ->whereNull('invoice_id')
            ->whereNull('invoice_reminded_at')
            ->where('status', '!=', TerminStatus::Paid->value)
            ->get();

        foreach ($termins as $termin) {
            $project = $termin->project;
            $milestone = $termin->trigger === PaymentTermTrigger::Milestone
                ? $project->milestones->first(fn (Milestone $m) => mb_strtolower(trim($m->name)) === mb_strtolower(trim((string) $termin->milestone_name)))
                : null;

            $due = match ($termin->trigger) {
                PaymentTermTrigger::DiMuka => true,
                PaymentTermTrigger::Tanggal => $termin->scheduled_date !== null && $termin->scheduled_date->toDateString() <= $today,
                PaymentTermTrigger::Milestone => $milestone?->status === MilestoneStatus::Completed,
                PaymentTermTrigger::ProyekSelesai => $project->status === ProjectStatus::Completed,
            };

            if (! $due) {
                continue;
            }

            $termin->update(array_filter([
                'invoice_reminded_at' => now(),
                'milestone_id' => $milestone?->id,
            ]));

            $marketing = $project->lead?->assignee;
            $title = "Terbitkan Invoice Termin {$termin->termin_number}";
            $message = "Termin {$termin->termin_number} proyek \"{$project->name}\" (".'Rp '.number_format((float) $termin->amount, 0, ',', '.').') sudah waktunya ditagih — '.$termin->trigger->label().'.';
            $metadata = ['termin_id' => $termin->id, 'project_id' => $project->id];

            $marketing
                ? $this->notificationService->notifyMany([$marketing], 'termin_invoice_due', $title, $message, $metadata)
                : $this->notificationService->notifyRoles(['MARKETING'], 'termin_invoice_due', $title, $message, $metadata);
            $sent++;
        }

        return $sent;
    }

    /** Money math in integer cents — avoids float drift on decimal(15,2) sums/comparisons. */
    private function toCents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }
}
