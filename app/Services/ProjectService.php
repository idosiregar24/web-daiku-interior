<?php

namespace App\Services;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\Project;
use Illuminate\Validation\ValidationException;

class ProjectService
{
    /**
     * PRD §4.4: "Project hanya bisa dibuat dari Lead yang berstatus DEAL".
     * Two callers: `LeadController::confirmDeal()` (Marketing closing a
     * deal — bundles the Lead status change via `LeadService::confirmDeal()`)
     * and `ProjectController::store()` (PM creating directly for a lead
     * that's already DEAL_DESAIN/CLOSING). Both funnel through here so the
     * "one project per lead" / status-eligibility rules live in one place.
     */
    public function createFromLead(Lead $lead, array $data): Project
    {
        if (! in_array($lead->status, [LeadStatus::DealDesain, LeadStatus::Closing], true)) {
            throw ValidationException::withMessages([
                'lead_id' => 'Proyek hanya bisa dibuat dari lead berstatus DEAL_DESAIN atau CLOSING.',
            ]);
        }

        if ($lead->project()->exists()) {
            throw ValidationException::withMessages([
                'lead_id' => 'Lead ini sudah punya proyek.',
            ]);
        }

        return Project::create([
            'lead_id' => $lead->id,
            'name' => $data['name'],
            'pm_id' => $data['pm_id'],
            'start_date' => $data['start_date'],
            'contract_value' => $data['contract_value'],
        ]);
    }
}
