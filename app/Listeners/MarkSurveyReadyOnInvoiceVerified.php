<?php

namespace App\Listeners;

use App\Enums\InvoiceType;
use App\Events\InvoiceVerified;
use App\Models\LeadSurvey;
use App\Services\LeadService;

/**
 * Sprint 12 decision #3 — an outside-Pekanbaru survey only goes ahead once
 * its RAB Jasa Survey is paid: Finance verifying that invoice is the only
 * way the survey becomes SIAP (LeadService::markSurveyReady() has no route).
 */
class MarkSurveyReadyOnInvoiceVerified
{
    public function __construct(private LeadService $leadService) {}

    public function handle(InvoiceVerified $event): void
    {
        $invoice = $event->invoice;

        if ($invoice->type !== InvoiceType::JasaSurvey || $invoice->quotation_id === null) {
            return;
        }

        LeadSurvey::where('quotation_id', $invoice->quotation_id)
            ->get()
            ->each(fn (LeadSurvey $survey) => $this->leadService->markSurveyReady($survey));
    }
}
