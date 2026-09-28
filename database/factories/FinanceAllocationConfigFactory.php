<?php

namespace Database\Factories;

use App\Enums\FinanceCategory;
use App\Models\FinanceAllocationConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceAllocationConfig>
 */
class FinanceAllocationConfigFactory extends Factory
{
    public function definition(): array
    {
        return [
            'label' => fake()->unique()->word(),
            'percentage' => 1,
            'kategori' => FinanceCategory::Operasional->value,
            'is_active' => true,
        ];
    }
}
