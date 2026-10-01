<?php

namespace Database\Factories;

use App\Enums\AssetCondition;
use App\Models\Asset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Mesin Potong', 'Bor Listrik', 'Kompresor', 'Mobil Pickup']).' '.fake()->numerify('#'),
            'category' => fake()->randomElement(['Alat', 'Mesin', 'Kendaraan']),
            'purchase_date' => fake()->dateTimeBetween('-3 years', '-1 month'),
            'value' => fake()->randomFloat(2, 1_000_000, 150_000_000),
            'condition' => AssetCondition::Good->value,
            'location' => 'Gudang Workshop',
            'notes' => null,
        ];
    }

    /**
     * An installment plan with nothing paid yet (PRD §4.7 "Aset & Cicilan").
     * Record payments through AssetInstallmentService::recordPayment() so
     * `paid_install`, the ledger and the FinanceTransaction stay in sync.
     */
    public function withInstallmentPlan(float $total = 60_000_000, ?float $perPayment = 5_000_000, ?int $dueDay = 10): static
    {
        return $this->state(fn () => [
            'has_installment' => true,
            'total_install' => $total,
            'paid_install' => 0,
            'installment_amount' => $perPayment,
            'installment_due_day' => $dueDay,
        ]);
    }
}
