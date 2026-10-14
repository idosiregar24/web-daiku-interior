<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Sprint 23 K9 / K11 — a note on one RAB version that informs and never
 * decides: the CEO's note (KIND_CEO — the CEO no longer approves, Sprint
 * 23 K8), the client's reason given with "Tidak Setuju" on the public
 * link (KIND_CLIENT, no internal author), and what Marketing adds before
 * forwarding that rejection to the Estimator (KIND_MARKETING). Shown on
 * the RAB page to the Estimator, PM / Asisten PM, Marketing and CEO.
 * Written only by QuotationService; append-only like AuditLog.
 */
class QuotationNote extends Model
{
    public const KIND_CEO = 'CEO';

    public const KIND_CLIENT = 'CLIENT';

    public const KIND_MARKETING = 'MARKETING';

    /** Longest note accepted (Form Requests mirror it). */
    public const MAX_LENGTH = 1000;

    protected $fillable = [
        'quotation_id',
        'version',
        'kind',
        'author_id',
        'note',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Catatan RAB bersifat append-only dan tidak bisa diubah.'));
        static::deleting(fn () => throw new LogicException('Catatan RAB bersifat append-only dan tidak bisa dihapus.'));
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /** Null for the client's note (no login on the public link). */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
