<?php

namespace Database\Factories;

use App\Models\Division;
use App\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'division_id' => Division::factory(),
            'name' => fake()->randomElement(['Admin', 'Marketing', 'Desainer', 'Estimator', 'Drafter', 'Staf Gudang'])
                .' '.fake()->unique()->numberBetween(1, 99999),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
