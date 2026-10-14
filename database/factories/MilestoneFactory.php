<?php

namespace Database\Factories;

use App\Enums\MilestoneStatus;
use App\Models\Milestone;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

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
            // Sprint 23 K17 — a timeline runs start → end (target_date).
            'start_date' => fn (array $attributes) => Carbon::parse($attributes['target_date'])->subDays(7)->toDateString(),
            'target_date' => fake()->dateTimeBetween('now', '+2 months'),
            'status' => MilestoneStatus::Pending->value,
            'order' => 0,
        ];
    }
}
