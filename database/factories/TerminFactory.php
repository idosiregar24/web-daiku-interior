<?php

namespace Database\Factories;

use App\Enums\TerminStatus;
use App\Models\Project;
use App\Models\Termin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Termin>
 */
class TerminFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'milestone_id' => null,
            'termin_number' => 1,
            'percentage' => 30,
            'amount' => fake()->randomFloat(2, 5_000_000, 50_000_000),
            'scheduled_date' => fake()->dateTimeBetween('now', '+2 months'),
            'status' => TerminStatus::Scheduled->value,
            'bank_account_id' => null,
            'invoice_url' => null,
            'paid_at' => null,
        ];
    }
}
