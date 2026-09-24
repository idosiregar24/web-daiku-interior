<?php

namespace Database\Factories;

use App\Enums\QuotationStatus;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quotation>
 */
class QuotationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'total_amount' => 0,
            'status' => QuotationStatus::Draft->value,
            'valid_until' => null,
            'version' => 1,
            'created_by' => User::factory(),
        ];
    }

    /** Cleared both internal gates (CEO→PM) — ready for Marketing's deal confirmation. */
    public function sentToClient(): static
    {
        return $this->state(['status' => QuotationStatus::SentToClient->value, 'total_amount' => 150_000_000]);
    }

    /** Client accepted (LeadService::confirmDeal()) — a Project may be created from its lead. */
    public function approved(): static
    {
        return $this->state(['status' => QuotationStatus::Approved->value, 'total_amount' => 150_000_000]);
    }
}
