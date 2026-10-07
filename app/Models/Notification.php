<?php

namespace App\Models;

use App\Enums\NotificationPriority;
use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRD §4.9 in-app notification. Written only via
 * NotificationService::notify(), which also delivers it.
 *
 * `type` stays a plain string column (not an enum cast) so a row whose
 * type was later renamed or split still loads — notificationType() maps
 * it, and an unknown one reads as Info. `priority` / `category` are
 * serialized with the row for the bell (Sprint 18).
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
        'read_at',
        'repushed_at',
        'metadata',
    ];

    /** @var list<string> */
    protected $appends = ['priority', 'category'];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'read_at' => 'datetime',
            'repushed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function notificationType(): ?NotificationType
    {
        return NotificationType::fromStored($this->type);
    }

    protected function priority(): Attribute
    {
        return Attribute::get(fn () => ($this->notificationType()?->priority() ?? NotificationPriority::Info)->value);
    }

    /** Null for an unknown legacy type — such a row can't be muted per category. */
    protected function category(): Attribute
    {
        return Attribute::get(fn () => $this->notificationType()?->category()->value);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
