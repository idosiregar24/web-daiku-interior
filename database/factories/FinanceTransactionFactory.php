<?php

namespace Database\Factories;

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Models\FinanceTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceTransaction>
 */
class FinanceTransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => null,
            'bank_account_id' => null,
            'type' => FinanceTransactionType::Expense->value,
            'kategori' => FinanceCategory::Operasional->value,
            'amount' => fake()->randomFloat(2, 100_000, 1_000_000),
            'description' => fake()->sentence(),
            'reference_id' => null,
            'date' => now()->toDateString(),
            'created_by' => User::factory(),
        ];
    }
}
