<?php

namespace App\Services;

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Models\BankAccount;
use App\Models\FamilyGatheringFund;
use App\Models\Penalty;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PRD §4.7 "Dana penalti family gathering tidak bisa dicairkan tanpa
 * record Penggunaan Dana" — Finance's manual half of the ledger; the
 * automated INCOME half is PenaltyService::runDailyCheck() (PRD §6.5,
 * one row per penalty, written when the penalty is issued).
 *
 * Sprint 9 decision #10: penalties are paid by the tukang manually, so an
 * issued penalty is money *owed*, not money held. Only income whose
 * penalty is already paid (PenaltyCollectionService) — plus income not
 * tied to a penalty at all — can be spent, and every expense leaves a
 * bank account as a PENGELUARAN transaction so per-account balances stay
 * right (PRD §4.7 "Setiap transaksi wajib mencantumkan rekening").
 */
class FamilyGatheringFundService
{
    /**
     * Kategori of the PENGELUARAN a fund expense writes. LAINNYA, not
     * KONSUMSI/OPERASIONAL: a gathering pays for venue, transport, gifts
     * and food alike, and KONSUMSI/OPERASIONAL are project-cost allocations
     * (FinanceAllocationService) this money doesn't belong to. The fund
     * row's `finance_transaction_id` is what identifies it as fund usage.
     */
    public const EXPENSE_CATEGORY = FinanceCategory::Lainnya;

    public function __construct(
        private FinanceTransactionService $financeTransactionService,
        private AuditLogService $auditLogService,
    ) {}

    /**
     * The fund page's numbers, all from the ledger so they add up:
     * spendable = collected + otherIncome − totalExpense.
     *
     * @return array{penaltyTotal: float, collected: float, outstanding: float, otherIncome: float, totalExpense: float, spendable: float}
     */
    public function summary(): array
    {
        $penaltyTotal = (float) FamilyGatheringFund::query()->income()->whereNotNull('source_penalty_id')->sum('amount');
        $collected = (float) FamilyGatheringFund::query()->income()
            ->whereIn('source_penalty_id', Penalty::query()->paid()->select('id'))
            ->sum('amount');
        $otherIncome = (float) FamilyGatheringFund::query()->income()->whereNull('source_penalty_id')->sum('amount');
        $totalExpense = (float) FamilyGatheringFund::query()->expense()->sum('amount');

        return [
            'penaltyTotal' => round($penaltyTotal, 2),
            'collected' => round($collected, 2),
            'outstanding' => round($penaltyTotal - $collected, 2),
            'otherIncome' => round($otherIncome, 2),
            'totalExpense' => round($totalExpense, 2),
            'spendable' => round($collected + $otherIncome - $totalExpense, 2),
        ];
    }

    /** Income from paid penalties (+ income not tied to a penalty) − recorded usage. */
    public function spendableBalance(): float
    {
        return $this->summary()['spendable'];
    }

    /**
     * Records a "Penggunaan Dana": a PENGELUARAN transaction out of the
     * chosen account plus the EXPENSE ledger row linked to it, audited
     * together. Usage beyond the spendable balance would pay out money
     * that was never collected (the demo data once drove the fund to
     * −Rp 100.000) — the ledger rows are locked while checking so two
     * concurrent expenses can't both pass. Rules are re-checked here, not
     * only in RecordFundExpenseRequest, for callers that skip the request
     * (the demo seeder).
     *
     * @param  array{amount: numeric-string|float|int, description: string, bank_account_id: int|string|null, date?: string|null}  $data
     */
    public function recordExpense(array $data, User $actor): FamilyGatheringFund
    {
        $amount = round((float) $data['amount'], 2);
        $date = substr((string) ($data['date'] ?? now()->toDateString()), 0, 10);
        $description = trim((string) $data['description']);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal harus lebih dari 0.']);
        }

        if ($date > now()->toDateString()) {
            throw ValidationException::withMessages(['date' => 'Tanggal penggunaan dana tidak boleh di masa depan.']);
        }

        return DB::transaction(function () use ($data, $actor, $amount, $date, $description) {
            $account = BankAccount::query()
                ->whereKey($data['bank_account_id'] ?? null)
                ->where('is_active', true)
                ->first();

            if (! $account) {
                throw ValidationException::withMessages([
                    'bank_account_id' => 'Rekening wajib dipilih dan harus aktif.',
                ]);
            }

            FamilyGatheringFund::query()->lockForUpdate()->get(['id']);
            $spendable = $this->spendableBalance();

            if ($amount > $spendable) {
                throw ValidationException::withMessages([
                    'amount' => 'Saldo dana yang bisa dipakai tidak cukup — tersedia Rp '
                        .number_format($spendable, 0, ',', '.').' (hanya dari penalti yang sudah dibayar).',
                ]);
            }

            $transaction = $this->financeTransactionService->create([
                'bank_account_id' => $account->id,
                'type' => FinanceTransactionType::Expense->value,
                'kategori' => self::EXPENSE_CATEGORY->value,
                'amount' => $amount,
                'description' => "Penggunaan Dana Family Gathering — {$description}",
                'date' => $date,
            ], $actor);

            $entry = FamilyGatheringFund::create([
                'type' => 'EXPENSE',
                'amount' => $amount,
                'description' => $description,
                'finance_transaction_id' => $transaction->id,
                'recorded_by' => $actor->id,
            ]);

            // PRD §9.4 "perubahan finance" — the "record Penggunaan Dana" itself.
            $this->auditLogService->record(
                'finance.family_fund_expense',
                $entry,
                ['spendable' => $spendable],
                [
                    'amount' => $amount,
                    'description' => $description,
                    'bank_account_id' => $account->id,
                    'finance_transaction_id' => $transaction->id,
                    'date' => $date,
                    'spendable' => round($spendable - $amount, 2),
                ],
                $actor,
            );

            return $entry;
        });
    }
}
