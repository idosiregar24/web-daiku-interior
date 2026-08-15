<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only (PRD §9.4 spirit — approval decisions are never edited or
 * deleted once recorded). No controller/route wired to this yet — see
 * the migration's docblock; the approval flow itself lands
 * .claude/plan/sprint-03.md Week 5.
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
