<?php

namespace App\Models;

use App\Enums\SiteFindingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 24 D12 — a finishing finding the Pengawas records on site. While
 * TERBUKA it holds its timeline's QA approval (or every timeline's, when
 * it names none) and the project's completion.
 */
class SiteFinding extends Model
{
    protected $fillable = [
        'project_id',
        'milestone_id',
        'description',
        'photo_url',
        'status',
        'reported_by',
        'task_id',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SiteFindingStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', SiteFindingStatus::Terbuka->value);
    }
}
