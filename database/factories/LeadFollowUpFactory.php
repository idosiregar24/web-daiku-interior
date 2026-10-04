<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\LeadFollowUp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadFollowUp>
 */
class LeadFollowUpFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            // Next number for the lead.
            'sequence' => fn (array $attributes) => (int) LeadFollowUp::where('lead_id', $attributes['lead_id'])->max('sequence') + 1,
            'scheduled_date' => fake()->dateTimeBetween('-1 week', '+2 weeks')->format('Y-m-d'),
            'created_by' => null,
        ];
    }

    public function done(string $note = 'Sudah dihubungi'): static
    {
        return $this->state(fn () => ['result_note' => $note])->afterCreating(
            fn (LeadFollowUp $followUp) => $followUp->forceFill(['done_at' => now()])->save(),
        );
    }
}
