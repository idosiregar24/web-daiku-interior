<?php

namespace Database\Factories;

use App\Models\Division;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Division>
 */
class DivisionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Desain', 'Presales', 'Proyek', 'Keuangan', 'Logistik', 'Umum', 'QA'])
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
