<?php

namespace App\Listeners;

use App\Enums\QuotationType;
use App\Events\QuotationClientApproved;
use App\Models\ProjectOpening;
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

        // Sprint 17 Sub 03 (K2) — nothing for Marketing to do yet (the DP is
        // billed from the project's termin once the CEO opens it, then it
        // shows in their Perlu Tindakan as "Termin perlu diterbitkan invoice").
        $this->notificationService->notifyMany(
            [$quotation->lead->assignee ?? $event->link->sender],
            'project_rab_awaiting_opening',
            'RAB Proyek Disetujui Klien — Menunggu CEO Buka Proyek',
            "Klien \"{$quotation->lead->client_name}\" menyetujui {$quotation->title()}. Menunggu CEO Buka Proyek; tagihan DP muncul di Perlu Tindakan setelah proyek dibuka.",
            ['quotation_id' => $quotation->id, 'lead_id' => $quotation->lead_id],
        );
    }
}
