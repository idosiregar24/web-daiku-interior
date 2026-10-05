<?php

namespace App\Listeners;

use App\Enums\QuotationType;
use App\Events\QuotationClientApproved;
use App\Services\LeadService;

/**
 * Sprint 12 Sub 5 — the client approving the RAB Proyek is the deal
 * (replaces Marketing's "Konfirmasi Deal"): the lead closes and its design
 * goes into production. An addendum (RAB tambahan, Sub 12) belongs to an
 * existing deal and changes nothing here. Runs inside the approval's
 * transaction (QuotationService::clientApprove()).
 */
class CloseLeadOnProjectRabApproval
{
    public function __construct(private LeadService $leadService) {}

    public function handle(QuotationClientApproved $event): void
    {
        $quotation = $event->quotation;

        if ($quotation->type !== QuotationType::Proyek || $quotation->parent_quotation_id !== null) {
            return;
        }

        $this->leadService->closeOnProjectRabApproval($quotation->lead, $quotation, $event->link->sender);
    }
}
