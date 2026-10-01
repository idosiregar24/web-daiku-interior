<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only (PRD §9.4 spirit — approval decisions are never edited or
 * deleted once recorded); written only from QuotationService's
 * ceoDecision()/pmDecision()/clientReject() — no direct controller/route
 * of its own. `approver_role` is CEO, PM or CLIENT; for CLIENT the
 * approver is the CEO/Marketing user who recorded the client's decision.
 * `version` is the quotation version the decision was about.
 */
class QuotationApproval extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'quotation_id',
        'version',
        'approver_id',
        'approver_role',
        'status',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
