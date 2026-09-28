<?php

namespace Database\Factories;

use App\Models\StaffLoan;
use App\Models\StaffLoanPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Raw row only — it does NOT bump the loan's `paid_amount`. Go through
 * StaffLoanService::recordPayment() when the balance matters.
 *
 * @extends Factory<StaffLoanPayment>
 */
class StaffLoanPaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'staff_loan_id' => StaffLoan::factory(),
            'amount' => 100_000,
            'paid_date' => now()->toDateString(),
            'note' => null,
            'task_id' => null,
            'created_by' => User::factory(),
        ];
    }
}
