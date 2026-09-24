<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRD §4.9 in-app notification. Written only via
 * NotificationService::notify(), which also broadcasts it.
 */
class Notification extends Model
{
    use HasFactory;

    /**
     * The table has `created_at` only. Eloquent stamps it from the app
     * clock (Asia/Jakarta) rather than leaving it to the column's
     * `useCurrent()` default — the DB clock may be UTC (SQLite always,
     * the Docker MySQL by default), which would skew the 90-day cutoff,
     * the per-day reminder guard and "5 menit lalu" by seven hours.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'message',
        'is_read',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
