<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Cabang '.fake()->city(),
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'address' => fake()->address(),
        ];
    }
}
