<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Sprint 12 decision #8 — one ✔ OK / ✘ SALAH mark on one RAB item by the
 * PM / Asisten PM (stage PM), for one quotation version. Written only by
 * QuotationService::review(). Append-only like QuotationRevision: the KPI
 * data of decision #9 can't be rewritten.
 *
 * Sprint 23 K7: a ✔ copied from an earlier version for an unchanged item
 * has `carried_from_version` (the version the PM really checked it in);
 * `item_fingerprint` (QuotationItem::fingerprint()) is how the next
 * version recognises the same item. Stage CEO rows are Sprint 12 history
 * — the CEO no longer reviews (K8).
 */
class QuotationItemReview extends Model
{
    public const UPDATED_AT = null;

    public const STAGE_PM = 'PM';

    /** @deprecated Sprint 12 — the CEO's review of a RAB Proyek, removed in Sprint 23 (K8). */
    public const STAGE_CEO = 'CEO';

    public const VERDICT_OK = 'OK';

    public const VERDICT_SALAH = 'SALAH';

    protected $fillable = [
        'quotation_id',
        'version',
        'quotation_item_id',
        'item_description',
        'section_name',
        'stage',
        'reviewer_id',
        'verdict',
        'note',
        'carried_from_version',
        'item_fingerprint',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'carried_from_version' => 'integer'];
    }

    /** Copied from an earlier version (K7) rather than marked by the reviewer now. */
    public function isCarried(): bool
    {
        return $this->carried_from_version !== null;
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Review item RAB bersifat append-only dan tidak bisa diubah.'));
        static::deleting(fn () => throw new LogicException('Review item RAB bersifat append-only dan tidak bisa dihapus.'));
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(QuotationItem::class, 'quotation_item_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
