<?php

namespace Database\Factories;

use App\Models\FamilyGatheringFund;
use App\Models\Penalty;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FamilyGatheringFund>
 */
class FamilyGatheringFundFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => 'INCOME',
            'amount' => 50000,
            'description' => 'Penalti form harian',
            'source_penalty_id' => null,
            'recorded_by' => User::factory(),
        ];
    }

    /** The INCOME row PenaltyService writes when `$penalty` is issued. */
    public function forPenalty(Penalty $penalty): static
    {
        return $this->state(fn () => [
            'type' => 'INCOME',
            'amount' => $penalty->amount,
            'source_penalty_id' => $penalty->id,
        ]);
    }
}
