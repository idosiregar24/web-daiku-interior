<?php

namespace App\Listeners;

use App\Events\QuotationClientApproved;
use App\Services\DesignService;

/**
 * Sprint 12 decision #16 — the client approved a RAB Jasa Desain: the
 * design is opened locked (MENUNGGU_BAYAR) and the Kepala Desain is told
 * "disetujui client, belum bayar". Marketing is prompted for the invoice
 * by PromptInvoiceOnServiceRabApproval; paying it unlocks the design
 * (UnlockDesignOnInvoiceVerified).
 */
class OpenDesignOnRabDesainApproval
{
    public function __construct(private DesignService $designService) {}

    public function handle(QuotationClientApproved $event): void
    {
        $this->designService->openFromQuotation($event->quotation);
    }
}
