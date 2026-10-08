<?php

namespace App\Listeners;

use App\Enums\InvoiceType;
use App\Enums\NotificationType;
use App\Events\InvoiceVerified;
use App\Models\LeadSurvey;
use App\Models\Quotation;
use App\Services\LeadService;
use App\Services\NotificationService;
use App\Services\QuotationService;

/**
 * Sprint 12 decision #3 — an outside-Pekanbaru survey only goes ahead once
 * its RAB Jasa Survey is paid: Finance verifying that invoice is the only
 * way the survey becomes SIAP (LeadService::markSurveyReady() has no route).
 * Sprint 17 Sub 02: a survey not yet paired with the RAB is paired first,
 * and the survey is found through either side of the link.
 * Sprint 19: paid before any survey was scheduled → Marketing gets the P1
 * "jadwalkan survey" instead (one P1 per payment either way).
 */
class MarkSurveyReadyOnInvoiceVerified
{
    public function __construct(
        private LeadService $leadService,
        private QuotationService $quotationService,
        private NotificationService $notificationService,
    ) {}

    public function handle(InvoiceVerified $event): void
    {
        $invoice = $event->invoice;

        if ($invoice->type !== InvoiceType::JasaSurvey || $invoice->quotation_id === null) {
            return;
        }

        $quotation = $invoice->quotation;

        if ($quotation?->lead !== null) {
            $this->quotationService->linkSurveyToRab($quotation->lead);
            $quotation->refresh();
        }

        LeadSurvey::query()
            ->where('quotation_id', $invoice->quotation_id)
            ->when($quotation?->lead_survey_id, fn ($query, int $surveyId) => $query->orWhere('id', $surveyId))
            ->get()
            ->each(fn (LeadSurvey $survey) => $this->leadService->markSurveyReady($survey));

        $this->promptScheduling($quotation);
    }

    private function promptScheduling(?Quotation $quotation): void
    {
        $lead = $quotation?->lead;

        if ($lead?->assignee === null || ! Quotation::query()->whereKey($quotation->id)->surveyToSchedule()->exists()) {
            return;
        }

        $this->notificationService->notify(
            $lead->assignee,
            NotificationType::SurveyToSchedule,
            'Survey Lunas — Jadwalkan Sekarang',
            "Pembayaran survey \"{$lead->client_name}\" sudah diverifikasi Finance, tapi jadwal survey belum dibuat. Tentukan tanggal & jam survey sekarang.",
            ['quotation_id' => $quotation->id, 'lead_id' => $lead->id],
        );
    }
}
