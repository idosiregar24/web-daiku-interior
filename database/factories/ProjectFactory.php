<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'name' => 'Proyek '.fake()->words(2, true),
            'pm_id' => User::factory(),
            'status' => ProjectStatus::Active->value,
            'start_date' => fake()->dateTimeBetween('-1 month', 'now'),
            'end_date' => null,
            'contract_value' => fake()->randomFloat(2, 10_000_000, 500_000_000),
        ];
    }
}
