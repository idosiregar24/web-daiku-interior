<?php

namespace App\Listeners;

use App\Enums\InvoiceType;
use App\Events\QuotationClientApproved;
use App\Services\NotificationService;

/**
 * Sprint 12 decision #20 — the client approved a RAB Jasa Survey / Jasa
 * Desain: Marketing (the lead's owner, else whoever sent the link) is told
 * to issue its invoice. The linked outside-Pekanbaru survey already waits
 * in MENUNGGU_BAYAR since it was scheduled (Sub 2) and stays there until
 * the invoice is verified (MarkSurveyReadyOnInvoiceVerified).
 */
class PromptInvoiceOnServiceRabApproval
{
    public function __construct(private NotificationService $notificationService) {}

    public function handle(QuotationClientApproved $event): void
    {
        $quotation = $event->quotation;
        $type = InvoiceType::forQuotation($quotation->type);

        if ($type === null) {
            return;
        }

        $this->notificationService->notifyMany(
            [$quotation->lead->assignee ?? $event->link->sender],
            'invoice_to_issue',
            "Terbitkan Invoice {$type->label()}",
            "Klien \"{$quotation->lead->client_name}\" menyetujui {$quotation->type->label()} — terbitkan invoice-nya.",
            ['quotation_id' => $quotation->id, 'lead_id' => $quotation->lead_id],
        );
    }
}
