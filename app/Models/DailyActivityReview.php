<?php

namespace App\Models;

use App\Enums\DailyActivityReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Sprint 29 Sub 13 (I2) — HR's check of one employee's day (`user_id` +
 * `date`). Append-only like DisciplinaryRecord: no `updated_at`, the model
 * refuses update/delete; checking again adds a row and the newest one
 * counts (highest id). Written only by DailyActivityService.
 */
class DailyActivityReview extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'date',
        'status',
        'note',
        'reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'status' => DailyActivityReviewStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Pemeriksaan aktivitas bersifat append-only dan tidak bisa diubah.'));
        static::deleting(fn () => throw new LogicException('Pemeriksaan aktivitas bersifat append-only dan tidak bisa dihapus.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('date', $date);
    }
}
