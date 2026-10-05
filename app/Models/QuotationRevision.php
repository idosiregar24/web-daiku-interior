<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * PRD §4.3 "Versi Revisi" — a frozen snapshot of one quotation version
 * (its RAB items and total) taken when a CEO, PM or client rejection sent
 * it back to DRAFT. Written only by QuotationService::closeVersion().
 * Append-only like AuditLog: beyond having no route of its own, the model
 * refuses updates and deletes, so a revision's history can't be rewritten
 * after the fact.
 */
class QuotationRevision extends Model
{
    public const UPDATED_AT = null;

    /** Who turned the version down — mirrors the TS `QuotationRevisionReason` union. */
    public const REASON_CEO_REJECTED = 'CEO_REJECTED';

    public const REASON_PM_REJECTED = 'PM_REJECTED';

    public const REASON_CLIENT_REJECTED = 'CLIENT_REJECTED';

    protected $fillable = [
        'quotation_id',
        'version',
        'total_amount',
        'items',
        'details',
        'reason',
        'note',
        'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'total_amount' => 'decimal:2',
            'items' => 'array',
            // Sprint 12: items total, discount, rounding, payment scheme (null on older snapshots).
            'details' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Riwayat revisi quotation bersifat append-only dan tidak bisa diubah.'));
        static::deleting(fn () => throw new LogicException('Riwayat revisi quotation bersifat append-only dan tidak bisa dihapus.'));
    }

    /** Maps a rejecting gate (QuotationApproval::approver_role — CEO/PM/CLIENT) to its reason code. */
    public static function reasonFor(string $approverRole): string
    {
        return match ($approverRole) {
            'CEO' => self::REASON_CEO_REJECTED,
            'PM' => self::REASON_PM_REJECTED,
            'CLIENT' => self::REASON_CLIENT_REJECTED,
        };
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /**
     * Who closed the version (the rejecting CEO/PM, or the CEO/Marketing
     * user who recorded the client's rejection). Named `closer`, not
     * `closedBy`: that would serialize to `closed_by` and overwrite the FK
     * column of the same name (same reason as Lead::assignee()).
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
