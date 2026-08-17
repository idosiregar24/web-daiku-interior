<?php

namespace Database\Factories;

use App\Enums\OvertimeStatus;
use App\Models\OvertimeRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OvertimeRequest>
 */
class OvertimeRequestFactory extends Factory
{
    public function definition(): array
    {
        $hours = fake()->randomFloat(2, 1, 6);
        $rate = fake()->randomElement([25000, 30000, 35000]);

        return [
            'staff_id' => User::factory(),
            'project_id' => Project::factory(),
            'task_id' => null,
            'hours' => $hours,
            'rate_per_hour' => $rate,
            'total_amount' => $hours * $rate,
            'work_date' => now()->toDateString(),
            'reason' => fake()->sentence(),
            'status' => OvertimeStatus::Pending->value,
        ];
    }
}
