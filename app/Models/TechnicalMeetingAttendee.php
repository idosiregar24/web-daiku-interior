<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sprint 24 E2 — a TM attendee: a system user, or a typed name for someone outside the company. */
class TechnicalMeetingAttendee extends Model
{
    protected $fillable = [
        'technical_meeting_id',
        'user_id',
        'name',
    ];

    protected $appends = ['label'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getLabelAttribute(): string
    {
        return $this->user?->name ?? (string) $this->name;
    }
}
