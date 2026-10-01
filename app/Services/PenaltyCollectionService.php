<?php

namespace App\Services;

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Models\BankAccount;
use App\Models\FamilyGatheringFund;
use App\Models\FinanceTransaction;
use App\Models\Penalty;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 9 decision #10 — penalties are paid by the tukang manually
 * (cash/transfer); wages are never deducted (StaffPaymentService doesn't
 * touch penalties). Finance records one payment per tukang covering one
 * or more unpaid penalties, all in one DB transaction:
 *
 *   - one PEMASUKAN PENALTY_COLLECT transaction on the receiving account
 *     (PENALTY_COLLECT is system-managed — FinanceCategory::systemManaged()
 *     — so it can't be entered through the manual "Catat Transaksi" form),
 *   - the penalties marked paid (`is_deducted` + `collected_at`/
 *     `collected_by`/`finance_transaction_id`) — which is what makes their
 *     Dana Family Gathering income spendable
 *     (FamilyGatheringFundService::summary()),
 *   - a `finance.penalty_collected` audit row (PRD §9.4).
 *
 * `reference_id` stays null: a payment covers several penalties, so the
 * link runs the other way (`penalties.finance_transaction_id`).
 */
class PenaltyCollectionService
{
    public function __construct(
        private FinanceTransactionService $financeTransactionService,
        private AuditLogService $auditLogService,
    ) {}

    /**
     * Rules are re-checked here, not only in RecordPenaltyPaymentRequest,
     * for callers that skip the request (the demo seeder). The selected
     * penalties are locked, so a double-click — or two Finance users at
     * once — can't pay the same penalty twice: the second request waits
     * on the lock, then finds it already paid.
     *
     * @param  array{penalty_ids: list<int|string>, bank_account_id: int|string, date: string, note?: ?string}  $data
     */
    public function recordPayment(User $staff, array $data, User $actor): FinanceTransaction
    {
        $ids = collect($data['penalty_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->sort()
            ->values();
        $date = substr((string) ($data['date'] ?? ''), 0, 10);
        $note = trim((string) ($data['note'] ?? ''));

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['penalty_ids' => 'Pilih minimal satu penalti yang dibayar.']);
        }

        if ($date === '' || $date > now()->toDateString()) {
            throw ValidationException::withMessages(['date' => 'Tanggal bayar wajib diisi dan tidak boleh di masa depan.']);
        }

        return DB::transaction(function () use ($staff, $data, $actor, $ids, $date, $note) {
            $account = BankAccount::query()
                ->whereKey($data['bank_account_id'] ?? null)
                ->where('is_active', true)
                ->first();

            if (! $account) {
                throw ValidationException::withMessages([
                    'bank_account_id' => 'Rekening penerima wajib dipilih dan harus aktif.',
                ]);
            }

            $penalties = Penalty::query()->whereKey($ids->all())->orderBy('id')->lockForUpdate()->get();

            if ($penalties->count() !== $ids->count()) {
                throw ValidationException::withMessages(['penalty_ids' => 'Sebagian penalti yang dipilih tidak ditemukan.']);
            }

            if ($penalties->contains(fn (Penalty $penalty) => (int) $penalty->staff_id !== (int) $staff->id)) {
                throw ValidationException::withMessages([
                    'penalty_ids' => "Penalti yang dipilih harus milik {$staff->name}.",
                ]);
            }

            if ($penalties->contains(fn (Penalty $penalty) => $penalty->is_deducted)) {
                throw ValidationException::withMessages([
                    'penalty_ids' => 'Sebagian penalti yang dipilih sudah dibayar — muat ulang halaman.',
                ]);
            }

            $count = $penalties->count();
            $total = round($penalties->sum(fn (Penalty $penalty) => (float) $penalty->amount), 2);

            $transaction = $this->financeTransactionService->create([
                'bank_account_id' => $account->id,
                'type' => FinanceTransactionType::Income->value,
                'kategori' => FinanceCategory::PenaltyCollect->value,
                'amount' => $total,
                'description' => "Pembayaran penalti {$staff->name} ({$count} penalti)".($note !== '' ? " — {$note}" : ''),
                'date' => $date,
            ], $actor);

            Penalty::query()->whereKey($ids->all())->update([
                'is_deducted' => true,
                'collected_at' => now(),
                'collected_by' => $actor->id,
                'finance_transaction_id' => $transaction->id,
            ]);

            $this->ensureFundIncome($penalties, $staff, $actor);

            // PRD §9.4 "perubahan finance".
            $this->auditLogService->record(
                'finance.penalty_collected',
                $transaction,
                ['penalty_ids' => $ids->all(), 'is_deducted' => false],
                [
                    'staff_id' => $staff->id,
                    'staff' => $staff->name,
                    'penalty_ids' => $ids->all(),
                    'is_deducted' => true,
                    'count' => $count,
                    'amount' => $total,
                    'bank_account_id' => $account->id,
                    'date' => $date,
                    'note' => $note !== '' ? $note : null,
                ],
                $actor,
            );

            return $transaction;
        });
    }

    /**
     * PenaltyService writes each penalty's INCOME row when it's issued —
     * except when no Finance/CEO user existed to record it. A paid penalty
     * must still reach the fund, so any row missing is written now.
     *
     * @param  Collection<int, Penalty>  $penalties
     */
    private function ensureFundIncome(Collection $penalties, User $staff, User $actor): void
    {
        $recorded = FamilyGatheringFund::query()
            ->income()
            ->whereIn('source_penalty_id', $penalties->modelKeys())
            ->pluck('source_penalty_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($penalties as $penalty) {
            if (in_array($penalty->id, $recorded, true)) {
                continue;
            }

            $reason = $penalty->type === 'DAILY_FORM_MISSING' ? 'Penalti form harian belum diisi' : 'Penalti';

            FamilyGatheringFund::create([
                'type' => 'INCOME',
                'amount' => $penalty->amount,
                'description' => "{$reason} — {$staff->name} ({$penalty->date_occurred->toDateString()})",
                'source_penalty_id' => $penalty->id,
                'recorded_by' => $actor->id,
            ]);
        }
    }
}
