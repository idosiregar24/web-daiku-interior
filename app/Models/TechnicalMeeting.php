<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Sprint 24 D3 — the project's Technical Meeting (one per project), written by TechnicalMeetingService. */
class TechnicalMeeting extends Model
{
    protected $fillable = [
        'project_id',
        'meeting_date',
        'notes',
        'recorded_by',
        'completed_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'meeting_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(TechnicalMeetingAttendee::class)->orderBy('id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
