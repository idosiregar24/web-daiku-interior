<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Sprint 12 decision #25 — the history of a project's budget allocation
 * (ProjectBudgetService). Append-only, like the audit log.
 */
class BudgetAllocationLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_POST_CREATED = 'post_created';

    public const ACTION_POST_RENAMED = 'post_renamed';

    public const ACTION_POSTS_REORDERED = 'posts_reordered';

    public const ACTION_POST_DELETED = 'post_deleted';

    public const ACTION_ITEMS_ALLOCATED = 'items_allocated';

    public const ACTION_ITEMS_UNALLOCATED = 'items_unallocated';

    protected $fillable = [
        'project_id',
        'user_id',
        'action',
        'before',
        'after',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Riwayat alokasi dana bersifat append-only dan tidak bisa diubah.'));
        static::deleting(fn () => throw new LogicException('Riwayat alokasi dana bersifat append-only dan tidak bisa dihapus.'));
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
