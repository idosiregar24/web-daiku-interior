<?php

namespace App\Services;

use App\Models\FamilyGatheringFund;
use App\Models\User;

/**
 * PRD §4.7 "Dana penalti family gathering tidak bisa dicairkan tanpa
 * record Penggunaan Dana" — Finance's manual half of the ledger; the
 * automated INCOME half is PenaltyService::runDailyCheck().
 */
class FamilyGatheringFundService
{
    public function recordExpense(array $data, User $actor): FamilyGatheringFund
    {
        return FamilyGatheringFund::create([
            'type' => 'EXPENSE',
            'amount' => $data['amount'],
            'description' => $data['description'],
            'recorded_by' => $actor->id,
        ]);
    }
}
