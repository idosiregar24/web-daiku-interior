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
}
