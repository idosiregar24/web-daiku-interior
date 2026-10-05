<?php

namespace App\Listeners;

use App\Events\InvoiceVerified;
use App\Services\TerminService;

/**
 * Sprint 12 #21 — a verified DP / termin / pelunasan invoice pays its
 * termin (TerminService::settleFromInvoice(); the income itself was booked
 * by InvoiceService::verify()).
 */
class SettleTerminOnInvoiceVerified
{
    public function __construct(private TerminService $terminService) {}

    public function handle(InvoiceVerified $event): void
    {
        $invoice = $event->invoice;

        if ($invoice->termin === null) {
            return;
        }

        $this->terminService->settleFromInvoice($invoice->termin, $invoice, $invoice->verifier);
    }
}
