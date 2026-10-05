<?php

namespace App\Events;

use App\Models\Quotation;
use App\Models\QuotationShareLink;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Sprint 12 decision #13 — the client pressed "Setujui Penawaran" on the
 * public link (QuotationService::clientApprove()). Dispatched inside that
 * transaction, so a failing listener rolls the approval back. Listeners:
 * the RAB Proyek closes the lead (CloseLeadOnProjectRabApproval); Sub 6–8
 * add survey payment, design start and "Buka Proyek".
 */
class QuotationClientApproved
{
    use Dispatchable;

    public function __construct(
        public Quotation $quotation,
        public QuotationShareLink $link,
    ) {}
}
