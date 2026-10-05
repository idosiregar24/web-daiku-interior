<?php

namespace Database\Factories;

use App\Enums\DesignStatus;
use App\Enums\QuotationStatus;
use App\Enums\QuotationType;
use App\Models\Design;
use App\Models\Lead;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Design>
 */
class DesignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'pic_id' => null,
            'status' => DesignStatus::Brief->value,
            'target_hari' => fake()->numberBetween(5, 30),
        ];
    }

    /** Sprint 12 #16 — born from a client-approved RAB Jasa Desain of the same lead. */
    public function fromRabDesain(DesignStatus $status = DesignStatus::MenungguBayar): static
    {
        return $this->state(fn () => [
            'status' => $status->value,
            'quotation_id' => fn (array $attributes) => Quotation::factory()->create([
                'lead_id' => $attributes['lead_id'],
                'type' => QuotationType::Desain->value,
                'status' => QuotationStatus::ClientApproved->value,
            ])->id,
        ]);
    }
}
