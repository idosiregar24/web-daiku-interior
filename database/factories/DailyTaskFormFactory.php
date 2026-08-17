<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\DailyTaskForm;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyTaskForm>
 */
class DailyTaskFormFactory extends Factory
{
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'staff_id' => User::factory(),
            'work_date' => now()->toDateString(),
            'status_update' => TaskStatus::OnProgress->value,
            'kendala' => null,
            'notes' => fake()->optional()->sentence(),
            'submitted_at' => now(),
        ];
    }
}
