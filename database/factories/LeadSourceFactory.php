<?php

namespace Database\Factories;

use App\Models\LeadSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadSource>
 */
class LeadSourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement([
                'Instagram', 'TikTok', 'Referral', 'Walk-in', 'WhatsApp', 'Marketplace', 'Iklan Sosmed', 'Website',
            ]),
        ];
    }
}
