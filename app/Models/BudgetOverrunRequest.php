<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Sprint 12 decision #28 — a realisation held back because it pushes its
 * post over budget, waiting for the CEO (BudgetRealizationService). Only
 * the decision columns ever change, once; never deleted.
 */
class BudgetOverrunRequest extends Model
{
    public const STATUS_WAITING = 'MENUNGGU';

    public const STATUS_APPROVED = 'DISETUJUI';

    public const STATUS_REJECTED = 'DITOLAK';

    protected $fillable = [
        'budget_post_id',
        'budget_line_id',
        'payload',
        'amount_over',
        'reason',
        'requested_by',
        'status',
        'decided_by',
        'decided_at',
        'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'amount_over' => 'decimal:2',
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Pengajuan overrun tidak bisa dihapus.'));
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(BudgetPost::class, 'budget_post_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'budget_line_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** The realisation recorded when the CEO approved it. */
    public function realization(): HasOne
    {
        return $this->hasOne(BudgetRealization::class, 'overrun_request_id');
    }

    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_WAITING);
    }
}
