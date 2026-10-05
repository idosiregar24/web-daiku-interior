<?php

namespace App\Models;

use App\Enums\PaymentTermTrigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 12 decision #12 — one row of a quotation's DP/termin scheme.
 * `amount` is derived from `percentage` × the quotation total by
 * QuotationService (never typed), so the rows always sum to the total.
 */
class QuotationPaymentTerm extends Model
{
    protected $fillable = ['quotation_id', 'sequence', 'label', 'percentage', 'amount', 'trigger', 'due_date', 'milestone_name'];

    protected function casts(): array
    {
        return [
            'trigger' => PaymentTermTrigger::class,
            'percentage' => 'decimal:2',
            'amount' => 'decimal:2',
            'due_date' => 'date:Y-m-d',
            'sequence' => 'integer',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }
}
