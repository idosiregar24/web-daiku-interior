<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\StaffLoan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffLoan>
 */
class StaffLoanFactory extends Factory
{
    public function definition(): array
    {
        $amount = fake()->randomElement([500_000, 1_000_000, 1_500_000, 2_000_000]);

        return [
            'staff_id' => User::factory(),
            'amount' => $amount,
            'paid_amount' => 0,
            'installment_amount' => $amount / 5,
            'description' => fake()->sentence(),
            'bank_account_id' => BankAccount::factory(),
            'created_by' => User::factory(),
        ];
    }
}
