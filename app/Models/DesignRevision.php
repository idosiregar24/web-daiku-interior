<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Sprint 12 decision #17 — one revision Marketing asked of the architect
 * (DesignService::requestRevision()). Append-only history.
 */
class DesignRevision extends Model
{
    protected $fillable = [
        'design_id',
        'sequence',
        'note',
        'requested_by',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Riwayat revisi desain bersifat append-only dan tidak bisa diubah.'));
        static::deleting(fn () => throw new LogicException('Riwayat revisi desain bersifat append-only dan tidak bisa dihapus.'));
    }

    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
