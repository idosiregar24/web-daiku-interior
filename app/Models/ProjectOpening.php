<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 12 decision #19 — a RAB Proyek the client approved, waiting for
 * the CEO's "Buka Proyek" (QueueProjectOpening → ProjectService::
 * openFromQuotation()).
 */
class ProjectOpening extends Model
{
    public const STATUS_WAITING = 'MENUNGGU_CEO';

    public const STATUS_OPENED = 'DIBUKA';

    protected $fillable = ['quotation_id', 'lead_id', 'status', 'opened_by', 'opened_at', 'project_id'];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime'];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_WAITING);
    }
}
