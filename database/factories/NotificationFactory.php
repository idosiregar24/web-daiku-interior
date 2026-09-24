<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => 'task_assigned',
            'title' => 'Task Baru',
            'message' => fake()->sentence(),
            'is_read' => false,
            'metadata' => [],
            'created_at' => now(),
        ];
    }
}
