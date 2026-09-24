<?php

namespace Database\Factories;

use App\Models\Material;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Material>
 */
class MaterialFactory extends Factory
{
    public function definition(): array
    {
        $cost = fake()->randomElement([45_000, 120_000, 185_000, 350_000]);

        return [
            'name' => fake()->randomElement(['Plywood', 'HPL', 'MDF', 'Engsel', 'Rel Laci', 'Lem Kayu']).' '.fake()->unique()->numerify('##'),
            'unit' => fake()->randomElement(['lembar', 'pcs', 'set', 'kg']),
            'category' => fake()->randomElement(['Kayu', 'Finishing', 'Hardware']),
            'cost_price' => $cost,
            'sell_price' => $cost * 1.3,
            'stock' => 50,
            'min_stock' => 10,
        ];
    }
}
