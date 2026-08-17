<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only (PRD §9.4 spirit — approval decisions are never edited or
 * deleted once recorded); written only from QuotationService's
 * ceoDecision()/pmDecision() — no direct controller/route of its own.
 */
class QuotationApproval extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'quotation_id',
        'approver_id',
        'approver_role',
        'status',
        'note',
    ];

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
