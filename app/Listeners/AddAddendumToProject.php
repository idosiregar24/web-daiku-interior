<?php

namespace App\Listeners;

use App\Enums\QuotationType;
use App\Events\QuotationClientApproved;
use App\Services\ProjectService;

/**
 * Sprint 12 decision #29 / D7 — the client approved a RAB Tambahan on its
 * link: it adds to the running project (contract value, termins TAMBAHAN,
 * allocatable items) instead of closing the lead or queueing a "Buka
 * Proyek" (CloseLeadOnProjectRabApproval / QueueProjectOpening skip
 * addenda via `parent_quotation_id`).
 */
class AddAddendumToProject
{
    public function __construct(private ProjectService $projectService) {}

    public function handle(QuotationClientApproved $event): void
    {
        $quotation = $event->quotation;

        if ($quotation->type !== QuotationType::Proyek || ! $quotation->isAddendum() || $quotation->project_id === null) {
            return;
        }

        $this->projectService->addAddendum($quotation);
    }
}
