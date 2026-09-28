<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\SupplierDebt;
use App\Models\SupplierDebtPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Raw row only — it does NOT bump the debt's `paid_amount` or write the
 * FinanceTransaction. Use SupplierDebtService::recordPayment() for that.
 *
 * @extends Factory<SupplierDebtPayment>
 */
class SupplierDebtPaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'supplier_debt_id' => SupplierDebt::factory(),
            'amount' => fake()->randomFloat(2, 100_000, 1_000_000),
            'paid_date' => now()->toDateString(),
            'bank_account_id' => BankAccount::factory(),
            'note' => null,
            'created_by' => User::factory(),
        ];
    }
}
