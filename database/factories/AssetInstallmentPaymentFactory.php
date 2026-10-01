<?php

namespace Database\Factories;

use App\Enums\FinanceCategory;
use App\Models\Asset;
use App\Models\AssetInstallmentPayment;
use App\Models\BankAccount;
use App\Models\FinanceTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Raw ledger row only — it does NOT bump the asset's `paid_install`. Use
 * AssetInstallmentService::recordPayment() for a real payment.
 *
 * @extends Factory<AssetInstallmentPayment>
 */
class AssetInstallmentPaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory()->withInstallmentPlan(),
            'amount' => fake()->randomFloat(2, 500_000, 5_000_000),
            'paid_at' => now()->toDateString(),
            'bank_account_id' => BankAccount::factory(),
            'finance_transaction_id' => FinanceTransaction::factory()->state(['kategori' => FinanceCategory::Angsuran->value]),
            'note' => null,
            'created_by' => User::factory(),
        ];
    }
}
