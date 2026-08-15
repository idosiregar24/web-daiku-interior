<?php

namespace Database\Factories;

use App\Enums\DesignStatus;
use App\Models\Design;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Design>
 */
class DesignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'pic_id' => null,
            'status' => DesignStatus::Brief->value,
            'target_hari' => fake()->numberBetween(5, 30),
        ];
    }
}
