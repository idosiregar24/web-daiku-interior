<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'milestone_id' => null,
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'assignee_id' => User::factory(),
            'created_by' => User::factory(),
            'due_date' => fake()->dateTimeBetween('now', '+2 weeks'),
            'status' => TaskStatus::Pending->value,
            'priority' => TaskPriority::Medium->value,
            'is_locked' => true,
            'rate_per_task' => fake()->randomFloat(2, 50_000, 500_000),
        ];
    }
}
