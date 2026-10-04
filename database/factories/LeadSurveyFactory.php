<?php

namespace Database\Factories;

use App\Enums\LeadSurveyStatus;
use App\Models\Lead;
use App\Models\LeadSurvey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadSurvey>
 */
class LeadSurveyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'sequence' => fn (array $attributes) => (int) LeadSurvey::where('lead_id', $attributes['lead_id'])->max('sequence') + 1,
            'scheduled_at' => fake()->dateTimeBetween('+1 day', '+2 weeks'),
            'address' => fake()->address(),
            'maps_url' => null,
            'is_outside_pekanbaru' => false,
            'status' => LeadSurveyStatus::Dijadwalkan->value,
            'created_by' => null,
        ];
    }

    public function outsidePekanbaru(): static
    {
        return $this->state(fn () => ['is_outside_pekanbaru' => true, 'status' => LeadSurveyStatus::MenungguBayar->value]);
    }
}
