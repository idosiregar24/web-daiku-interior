<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

/**
 * Invoice resource checks on top of the `finance.invoices.*` route roles.
 * SUPERADMIN bypasses via AppServiceProvider's Gate::before.
 */
class InvoicePolicy
{
    /**
     * Sprint 23 K12d — the payment proof photos: Finance (verifies), CEO
     * (reads finance) and Marketing (sent them). Nobody else ever gets the
     * file, whatever route they reach. Without an invoice: whether the
     * viewer may see proofs at all (the invoice list's thumbnails).
     */
    public function viewPaymentProofs(User $user, ?Invoice $invoice = null): bool
    {
        return $user->hasAnyRole(['FINANCE', 'CEO', 'MARKETING']);
    }
}
