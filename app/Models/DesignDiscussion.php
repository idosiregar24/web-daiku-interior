<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Sprint 12 decision #18 / D6 — one message in a design's Arsitek ↔
 * Estimator thread (DesignService::discuss()). Messages are never edited
 * or removed, so the thread stays a faithful record of what was agreed.
 */
class DesignDiscussion extends Model
{
    protected $fillable = [
        'design_id',
        'quotation_id',
        'user_id',
        'body',
        'attachment_url',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Pesan diskusi desain tidak bisa diubah.'));
        static::deleting(fn () => throw new LogicException('Pesan diskusi desain tidak bisa dihapus.'));
    }

    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
