<?php

namespace App\Services;

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Models\BankAccount;
use App\Models\FundTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PRD §4.7 "Pindah Dana" (Sprint 9 decision #5) — money moving between
 * two of the company's own accounts. One FundTransfer row plus two
 * FinanceTransaction legs, all in one DB transaction:
 *
 *   - PENGELUARAN PINDAH_DANA on the source account,
 *   - PEMASUKAN  PINDAH_DANA on the destination account,
 *
 * both with `reference_id = fund_transfers.id` — the same "business
 * record + reference_id" link loans and supplier debts use, so neither
 * leg is ever updated after insert (append-only, PRD §9.4) and each leg's
 * own `finance.transaction_created` audit row already carries the link.
 * The legs move each account's derived balance; company-level totals
 * leave them out (FinanceTransaction::scopeExcludingInternalTransfers()).
 * No "saldo cukup" check: no other expense flow has one, and a balance
 * that depends on complete bookkeeping shouldn't block recording a
 * transfer that really happened — the dialog warns instead.
 */
class FundTransferService
{
    public function __construct(
        private FinanceTransactionService $financeTransactionService,
        private AuditLogService $auditLogService,
    ) {}

    /**
     * Rules are re-checked here, not only in StoreFundTransferRequest,
     * because the demo seeder (and any future caller) reaches this
     * directly.
     *
     * @param  array{from_bank_account_id: int|string, to_bank_account_id: int|string, amount: numeric-string|float|int, date: string, description: string}  $data
     */
    public function transfer(array $data, User $actor): FundTransfer
    {
        $amount = round((float) $data['amount'], 2);
        $date = substr((string) $data['date'], 0, 10);

        if ((int) $data['from_bank_account_id'] === (int) $data['to_bank_account_id']) {
            throw ValidationException::withMessages([
                'to_bank_account_id' => 'Rekening tujuan harus berbeda dari rekening asal.',
            ]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal harus lebih dari 0.',
            ]);
        }

        if ($date > now()->toDateString()) {
            throw ValidationException::withMessages([
                'date' => 'Tanggal pindah dana tidak boleh di masa depan.',
            ]);
        }

        return DB::transaction(function () use ($data, $actor, $amount, $date) {
            $from = $this->activeAccount($data['from_bank_account_id'], 'from_bank_account_id', 'Rekening asal');
            $to = $this->activeAccount($data['to_bank_account_id'], 'to_bank_account_id', 'Rekening tujuan');

            $transfer = FundTransfer::create([
                'from_bank_account_id' => $from->id,
                'to_bank_account_id' => $to->id,
                'amount' => $amount,
                'date' => $date,
                'description' => trim($data['description']),
                'created_by' => $actor->id,
            ]);

            $leg = fn (BankAccount $account, FinanceTransactionType $type, string $description) => $this->financeTransactionService->create([
                'bank_account_id' => $account->id,
                'type' => $type->value,
                'kategori' => FinanceCategory::PindahDana->value,
                'amount' => $amount,
                'description' => $description,
                'reference_id' => $transfer->id,
                'date' => $date,
            ], $actor);

            $out = $leg($from, FinanceTransactionType::Expense, "Pindah dana ke {$to->label} — {$transfer->description}");
            $in = $leg($to, FinanceTransactionType::Income, "Pindah dana dari {$from->label} — {$transfer->description}");

            $this->auditLogService->record(
                'finance.fund_transferred',
                $transfer,
                null,
                [
                    'from_bank_account_id' => $from->id,
                    'from' => $from->label,
                    'to_bank_account_id' => $to->id,
                    'to' => $to->label,
                    'amount' => $amount,
                    'date' => $date,
                    'description' => $transfer->description,
                    'out_transaction_id' => $out->id,
                    'in_transaction_id' => $in->id,
                ],
                $actor,
            );

            return $transfer;
        });
    }

    private function activeAccount(int|string $id, string $field, string $label): BankAccount
    {
        $account = BankAccount::query()->whereKey($id)->where('is_active', true)->first();

        if (! $account) {
            throw ValidationException::withMessages([
                $field => "{$label} tidak valid atau tidak aktif.",
            ]);
        }

        return $account;
    }
}
