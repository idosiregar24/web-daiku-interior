<?php

namespace Database\Factories;

use App\Models\Penalty;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Penalty>
 */
class PenaltyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'staff_id' => User::factory(),
            'type' => 'DAILY_FORM_MISSING',
            'reference_id' => null,
            'amount' => 50000,
            'date_occurred' => now()->toDateString(),
            'is_deducted' => false,
        ];
    }
}
