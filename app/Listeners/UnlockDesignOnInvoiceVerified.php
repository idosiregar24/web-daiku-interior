<?php

namespace App\Listeners;

use App\Events\InvoiceVerified;
use App\Services\DesignService;

/**
 * Sprint 12 decision #16 — "Desain terkunci sampai pembayaran diverifikasi
 * Finance": a verified Jasa Desain invoice moves its design from
 * MENUNGGU_BAYAR to MENUNGGU_PENUGASAN (the only way) and tells the Kepala
 * Desain it's ready to be assigned.
 */
class UnlockDesignOnInvoiceVerified
{
    public function __construct(private DesignService $designService) {}

    public function handle(InvoiceVerified $event): void
    {
        $this->designService->unlockAfterPayment($event->invoice);
    }
}
