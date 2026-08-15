<?php

namespace Database\Factories;

use App\Enums\MilestoneStatus;
use App\Models\Milestone;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Milestone>
 */
class MilestoneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->randomElement(['3D Design', 'Produksi', 'Instalasi', 'Finishing']),
            'target_date' => fake()->dateTimeBetween('now', '+2 months'),
            'status' => MilestoneStatus::Pending->value,
            'order' => 0,
        ];
    }
}
