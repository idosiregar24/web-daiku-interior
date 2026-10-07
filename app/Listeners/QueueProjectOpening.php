<?php

namespace App\Listeners;

use App\Enums\QuotationType;
use App\Events\QuotationClientApproved;
use App\Models\ProjectOpening;
use App\Models\Quotation;
use App\Services\ActionInboxService;
use App\Services\NotificationService;

/**
 * Sprint 12 decision #19 — the client approved a RAB Proyek (not an
 * addendum): a "Buka Proyek" waits for the CEO (pop-up on every page,
 * and "Menunggu Dibuka" on the project list).
 */
class QueueProjectOpening
{
    public function __construct(private NotificationService $notificationService) {}

    public function handle(QuotationClientApproved $event): void
    {
        $quotation = $event->quotation;

        if ($quotation->type !== QuotationType::Proyek || $quotation->parent_quotation_id !== null) {
            return;
        }

        $opening = ProjectOpening::firstOrCreate(
            ['quotation_id' => $quotation->id],
            ['lead_id' => $quotation->lead_id, 'status' => ProjectOpening::STATUS_WAITING],
        );

        if (! $opening->wasRecentlyCreated) {
            return;
        }

        $this->notificationService->notifyRoles(
            ['CEO'],
            'project_opening_pending',
            'Buka Proyek',
            "Klien \"{$quotation->lead->client_name}\" menyetujui RAB Proyek (".'Rp '.number_format((float) $quotation->total_amount, 0, ',', '.').') — tentukan PM dan buka proyeknya.',
            ['project_opening_id' => $opening->id, 'lead_id' => $quotation->lead_id],
        );

        // Sprint 17 Sub 06 (K2, user's decision) — Marketing bills the DP right
        // away while the CEO opens the project (it's in their Perlu Tindakan
        // as "RAB disetujui klien — terbitkan invoice"). A scheme without a
        // "di muka" payment has nothing to bill yet: just the heads-up.
        $billable = Quotation::query()->whereKey($quotation->id)->billableUpfront()->exists();
        $client = $quotation->lead->client_name;

        $this->notificationService->notifyMany(
            [$quotation->lead->assignee ?? $event->link->sender],
            $billable ? 'invoice_to_issue' : 'project_rab_awaiting_opening',
            $billable ? 'Terbitkan Invoice DP' : 'RAB Proyek Disetujui Klien — Menunggu CEO Buka Proyek',
            $billable
                ? "Klien \"{$client}\" menyetujui {$quotation->title()} — terbitkan invoice DP sekarang; CEO sedang membuka proyeknya."
                : "Klien \"{$client}\" menyetujui {$quotation->title()}. Menunggu CEO Buka Proyek; tagihan termin muncul di Perlu Tindakan setelah waktunya.",
            ['quotation_id' => $quotation->id, 'lead_id' => $quotation->lead_id],
        );

        if ($billable) {
            ActionInboxService::forgetMarketingOf($quotation->lead->assignee);
        }
    }
}
