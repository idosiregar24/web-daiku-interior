<?php

namespace Database\Factories;

use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_name' => fake()->name(),
            'contact' => fake()->phoneNumber(),
            'source' => fake()->randomElement(['Instagram', 'Website', 'Referral/Rekomendasi', 'Walk-in', 'WhatsApp', 'TikTok', 'Marketplace', 'Existing', 'Iklan Sosmed', 'Lainnya']),
            'priority' => fake()->randomElement(LeadPriority::cases())->value,
            'category' => fake()->randomElement(['RESIDENTIAL', 'KOMERSIAL', 'DEVELOPER', 'KONTRAKTOR', 'LAINNYA']),
            'status' => LeadStatus::FollowUp->value,
            'assigned_to' => User::factory(),
            'follow_up_date' => fake()->dateTimeBetween('-1 week', '+2 weeks'),
            'notes' => fake()->optional()->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
