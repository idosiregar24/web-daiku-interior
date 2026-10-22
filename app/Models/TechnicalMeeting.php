<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** Sprint 24 D3 — the project's Technical Meeting (one per project), written by TechnicalMeetingService. */
class TechnicalMeeting extends Model
{
    protected $fillable = [
        'project_id',
        'meeting_date',
        'meeting_time',
        'mode',
        'invited_at',
        'reminded_day_before_at',
        'reminded_soon_at',
        'notes',
        'recorded_by',
        'completed_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'meeting_date' => 'date',
            'invited_at' => 'datetime',
            'reminded_day_before_at' => 'datetime',
            'reminded_soon_at' => 'datetime',
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

    /** Sprint 34 — scheduled ahead: invitation + reminders (else held right away, LANGSUNG). */
    public const MODE_SCHEDULED = 'JADWAL';

    public const MODE_NOW = 'LANGSUNG';

    /** The hour a TM without a time is reminded against. */
    public const DEFAULT_TIME = '09:00';

    /** When the meeting starts (Asia/Jakarta), the default hour when no time was set. */
    public function startsAt(): ?Carbon
    {
        if (! $this->meeting_date) {
            return null;
        }

        return Carbon::parse($this->meeting_date->toDateString().' '.substr($this->meeting_time ?? self::DEFAULT_TIME, 0, 5), 'Asia/Jakarta');
    }

    /** "Kamis, 22 Oktober 2026 pukul 10.00" */
    public function scheduleLabel(): string
    {
        $date = $this->meeting_date?->locale('id')->translatedFormat('l, j F Y') ?? '';

        return $this->meeting_time ? $date.' pukul '.str_replace(':', '.', substr($this->meeting_time, 0, 5)) : $date;
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
