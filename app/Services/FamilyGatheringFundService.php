<?php

namespace App\Services;

use App\Models\FamilyGatheringFund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PRD §4.7 "Dana penalti family gathering tidak bisa dicairkan tanpa
 * record Penggunaan Dana" — Finance's manual half of the ledger; the
 * automated INCOME half is PenaltyService::runDailyCheck().
 */
class FamilyGatheringFundService
{
    public function __construct(private AuditLogService $auditLogService) {}

    /** Collected penalties minus recorded usage. */
    public function balance(): float
    {
        return (float) FamilyGatheringFund::where('type', 'INCOME')->sum('amount')
            - (float) FamilyGatheringFund::where('type', 'EXPENSE')->sum('amount');
    }

    /**
     * The fund only holds what penalties collected — usage beyond the
     * balance would record money that doesn't exist (the demo data once
     * drove it to −Rp 100.000). The ledger rows are locked while
     * checking so two concurrent expenses can't both pass.
     */
    public function recordExpense(array $data, User $actor): FamilyGatheringFund
    {
        return DB::transaction(function () use ($data, $actor) {
            FamilyGatheringFund::query()->lockForUpdate()->get(['id']);
            $balance = $this->balance();

            if ((float) $data['amount'] > $balance) {
                throw ValidationException::withMessages([
                    'amount' => 'Saldo dana tidak cukup — tersedia Rp '.number_format($balance, 0, ',', '.').'.',
                ]);
            }

            $entry = FamilyGatheringFund::create([
                'type' => 'EXPENSE',
                'amount' => $data['amount'],
                'description' => $data['description'],
                'recorded_by' => $actor->id,
            ]);

            // PRD §9.4 "perubahan finance" — the "record Penggunaan Dana" itself.
            $this->auditLogService->record(
                'finance.family_fund_expense',
                $entry,
                ['balance' => $balance],
                ['amount' => $entry->amount, 'description' => $entry->description, 'balance' => $balance - (float) $entry->amount],
                $actor,
            );

            return $entry;
        });
    }
}
