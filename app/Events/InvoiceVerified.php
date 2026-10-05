<?php

namespace App\Events;

use App\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Sprint 12 decision #21 — Finance confirmed the money of an invoice came
 * in (InvoiceService::verify()). Dispatched inside that transaction, so a
 * failing listener rolls the verification back. Listeners: a Jasa Survey
 * invoice makes its survey ready (MarkSurveyReadyOnInvoiceVerified); Sub
 * 7–9 add termin paid, design unlocked and allocation opened.
 */
class InvoiceVerified
{
    use Dispatchable;

    public function __construct(public Invoice $invoice) {}
}
