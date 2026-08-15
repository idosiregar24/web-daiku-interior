<?php

namespace Database\Factories;

use App\Models\LeadCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadCategory>
 */
class LeadCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement([
                'RESIDENTIAL', 'KOMERSIAL', 'DEVELOPER', 'KONTRAKTOR', 'LAINNYA',
            ]),
        ];
    }
}
