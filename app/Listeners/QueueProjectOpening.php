<?php

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Enums\QuotationType;
use App\Events\QuotationClientApproved;
use App\Models\ProjectOpening;
use App\Models\Quotation;
use App\Models\User;
use App\Services\ActionInboxService;
use App\Services\NotificationService;

/**
 * The client approved a RAB Proyek (not an addendum): a "Buka Proyek"
 * waits (pop-up on every page, "Menunggu Dibuka" on the project list,
 * "Proyek siap dibuka" in Perlu Tindakan).
 *
 * Sprint 23 K13 — for the PM who approved that RAB (stored as the
 * opening's `opener_id`), or every PM when an Asisten PM approved it. The
 * CEO no longer opens; it hears about the project once it is opened
 * (ProjectService::createFromLead()).
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

        $opener = ProjectOpening::approvingPmOf($quotation);

        $opening = ProjectOpening::firstOrCreate(
            ['quotation_id' => $quotation->id],
            ['lead_id' => $quotation->lead_id, 'status' => ProjectOpening::STATUS_WAITING, 'opener_id' => $opener?->id],
        );

        if (! $opening->wasRecentlyCreated) {
            return;
        }

        $client = $quotation->lead->client_name;
        $amount = 'Rp '.number_format((float) $quotation->total_amount, 0, ',', '.');

        $this->notificationService->notifyMany(
            $opener ? [$opener] : User::role('PM')->where('is_active', true)->get(),
            NotificationType::ProjectOpeningPending,
            'Buka Proyek',
            $opener
                ? "Klien \"{$client}\" menyetujui RAB Proyek yang Anda setujui ({$amount}) — lengkapi data proyek lalu Buka Proyek."
                : "Klien \"{$client}\" menyetujui RAB Proyek ({$amount}) — PM mana pun dapat melengkapi data proyek lalu Buka Proyek.",
            ['project_opening_id' => $opening->id, 'lead_id' => $quotation->lead_id],
        );

        // Sprint 17 Sub 06 (K2, user's decision) — Marketing bills the DP right
        // away while the PM opens the project (it's in their Perlu Tindakan
        // as "RAB disetujui klien — terbitkan invoice"). A scheme without a
        // "di muka" payment has nothing to bill yet: just the heads-up.
        $billable = Quotation::query()->whereKey($quotation->id)->billableUpfront()->exists();
        $who = $opener ? "PM {$opener->name}" : 'PM';

        $this->notificationService->notifyMany(
            [$quotation->lead->assignee ?? $event->link->sender],
            $billable ? NotificationType::InvoiceToIssue : NotificationType::ProjectRabAwaitingOpening,
            $billable ? 'Terbitkan Invoice DP' : 'RAB Proyek Disetujui Klien — Menunggu PM Buka Proyek',
            $billable
                ? "Klien \"{$client}\" menyetujui {$quotation->title()} — terbitkan invoice DP sekarang; {$who} sedang membuka proyeknya."
                : "Klien \"{$client}\" menyetujui {$quotation->title()}. Menunggu {$who} Buka Proyek; tagihan termin muncul di Perlu Tindakan setelah waktunya.",
            ['quotation_id' => $quotation->id, 'lead_id' => $quotation->lead_id],
        );

        if ($billable) {
            ActionInboxService::forgetMarketingOf($quotation->lead->assignee);
        }
    }
}
