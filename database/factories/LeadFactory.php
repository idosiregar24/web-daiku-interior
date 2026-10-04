<?php

namespace Database\Factories;

use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadCategory;
use App\Models\LeadFollowUp;
use App\Models\LeadSource;
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
            // Linked to (or creates) the matching Data Master row, so an
            // overridden `source`/`category` string still gets a matching FK.
            'lead_source_id' => fn (array $attributes) => LeadSource::findOrCreateByName($attributes['source'])->id,
            'priority' => fake()->randomElement(LeadPriority::cases())->value,
            'category' => fake()->randomElement(['RESIDENTIAL', 'KOMERSIAL', 'DEVELOPER', 'KONTRAKTOR', 'LAINNYA']),
            'lead_category_id' => fn (array $attributes) => filled($attributes['category'] ?? null)
                ? LeadCategory::findOrCreateByName($attributes['category'])->id
                : null,
            'status' => LeadStatus::FollowUp->value,
            'assigned_to' => User::factory(),
            'notes' => fake()->optional()->sentence(),
            'created_by' => User::factory(),
        ];
    }

    /**
     * When a test overrides the FK directly, keep the legacy string
     * columns in sync with the chosen master row (mirrors LeadService).
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Lead $lead) {
            if ($lead->lead_source_id) {
                $lead->source = LeadSource::find($lead->lead_source_id)?->name ?? $lead->source;
            }

            if ($lead->lead_category_id) {
                $lead->category = LeadCategory::find($lead->lead_category_id)?->name ?? $lead->category;
            }
        });
    }

    /** Sprint 12 — the lead's next open follow-up (FU-n) falls on this date. */
    public function followUpOn(string|\DateTimeInterface $date): static
    {
        return $this->afterCreating(fn (Lead $lead) => LeadFollowUp::factory()->create([
            'lead_id' => $lead->id,
            'scheduled_date' => $date,
        ]));
    }
}
