<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * PRD §9.4 "Audit log tidak bisa dihapus oleh siapapun (termasuk CEO)".
 * Written only via AuditLogService::record(). Beyond having no
 * update/destroy route anywhere, the model itself refuses both — so no
 * future code path (a tinker session, a well-meaning cleanup job) can
 * quietly rewrite history through Eloquent.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'action',
        'model_type',
        'model_id',
        'old_values',
        'new_values',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log bersifat append-only dan tidak bisa diubah.'));
        static::deleting(fn () => throw new LogicException('Audit log bersifat append-only dan tidak bisa dihapus.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActionPrefix(Builder $query, ?string $prefix): Builder
    {
        return $query->when($prefix, fn (Builder $q) => $q->where('action', 'like', $prefix.'.%'));
    }
}
