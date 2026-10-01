<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Data Master — Rekening Bank (SUPERADMIN). The only stored money figure
 * on an account is its saldo awal (`opening_balance`, Sprint 9 decision
 * #5); every derived balance moves with it, so setting or changing it is
 * a "perubahan finance" and audited (PRD §9.4).
 */
class BankAccountService
{
    public function __construct(private AuditLogService $auditLogService) {}

    public function create(array $data, User $actor): BankAccount
    {
        return DB::transaction(function () use ($data, $actor) {
            $account = BankAccount::create($this->normalized($data));

            $this->auditLogService->record(
                'finance.bank_account_created',
                $account,
                null,
                $account->only(['bank_name', 'account_no', 'label', 'opening_balance', 'is_active']),
                $actor,
            );

            return $account;
        });
    }

    public function update(BankAccount $account, array $data, User $actor): BankAccount
    {
        return DB::transaction(function () use ($account, $data, $actor) {
            $oldOpeningBalance = $account->opening_balance;

            $account->update($this->normalized($data));

            if ($account->wasChanged('opening_balance')) {
                $this->auditLogService->record(
                    'finance.opening_balance_changed',
                    $account,
                    ['opening_balance' => $oldOpeningBalance],
                    ['opening_balance' => $account->opening_balance],
                    $actor,
                );
            }

            return $account;
        });
    }

    /** The form may send an empty saldo awal; the column is NOT NULL (default 0). */
    private function normalized(array $data): array
    {
        if (array_key_exists('opening_balance', $data) && $data['opening_balance'] === null) {
            $data['opening_balance'] = 0;
        }

        return $data;
    }
}
