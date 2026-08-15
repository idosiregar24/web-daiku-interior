<?php

namespace App\Services;

use App\Enums\LeadStatus;
use App\Models\Design;
use App\Models\Lead;
use Illuminate\Validation\ValidationException;

class DesignService
{
    /**
     * PRD §4.1 "Konversi ke Desain: Ketika status DEAL_DESAIN, sistem
     * membuka modul Desain untuk lead tersebut" — a Design record can
     * only be opened for a lead that has actually reached DEAL_DESAIN,
     * and only once (`leads.id` is unique on `designs`).
     */
    public function create(Lead $lead, array $data): Design
    {
        if ($lead->status !== LeadStatus::DealDesain) {
            throw ValidationException::withMessages([
                'lead_id' => 'Modul Desain hanya bisa dibuka untuk lead berstatus DEAL_DESAIN.',
            ]);
        }

        if ($lead->design()->exists()) {
            throw ValidationException::withMessages([
                'lead_id' => 'Lead ini sudah punya proyek desain.',
            ]);
        }

        return Design::create([
            ...$data,
            'lead_id' => $lead->id,
        ]);
    }
}
