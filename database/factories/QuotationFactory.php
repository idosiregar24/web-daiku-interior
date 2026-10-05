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
            'type' => 'PROYEK',
            'total_amount' => 0,
            'status' => QuotationStatus::Draft->value,
            'valid_until' => null,
            'version' => 1,
            'created_by' => User::factory(),
        ];
    }

    /** Approved internally and sent by Marketing — ready for the client's decision / deal confirmation. */
    public function sentToClient(): static
    {
        return $this->state(['status' => QuotationStatus::SentToClient->value, 'total_amount' => 150_000_000]);
    }

    /** Client accepted (LeadService::confirmDeal()) — a Project may be created from its lead. */
    public function approved(): static
    {
        return $this->state(['status' => QuotationStatus::ClientApproved->value, 'total_amount' => 150_000_000]);
    }
}
