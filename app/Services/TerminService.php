<?php

namespace App\Services;

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Enums\MilestoneStatus;
use App\Enums\TerminStatus;
use App\Models\BankAccount;
use App\Models\FinanceTransaction;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Termin;
use App\Models\User;
use Carbon\Carbon;
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
     * PRD §6.4: "PM membuat termin: bebas tentukan persentase. Validasi:
     * total semua persentase termin = 100%. scheduledDate otomatis =
     * Sabtu terdekat setelah milestone.targetDate" — enforced as a
     * ceiling here (a project's termins may sum to less than 100% while
     * PM is still scheduling the rest, but never more).
     */
    public function create(Project $project, array $data): Termin
    {
        $existingTotal = (int) $project->termins()->sum('percentage');

        if ($existingTotal + (int) $data['percentage'] > 100) {
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

    /** Money math in integer cents — avoids float drift on decimal(15,2) sums/comparisons. */
    private function toCents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }
}
