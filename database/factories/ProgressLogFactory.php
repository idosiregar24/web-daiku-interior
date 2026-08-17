<?php

namespace Database\Factories;

use App\Models\ProgressLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProgressLog>
 */
class ProgressLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'logged_by' => User::factory(),
            'percentage' => fake()->numberBetween(0, 100),
            'description' => fake()->sentence(),
            'ref_urls' => null,
            'log_date' => now()->toDateString(),
        ];
    }
}
