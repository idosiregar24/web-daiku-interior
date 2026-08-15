<?php

namespace App\Models;

use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lead extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_name',
        'contact',
        'source',
        'priority',
        'category',
        'service',
        'city',
        'gender',
        'order_detail',
        'status',
        'assigned_to',
        'follow_up_date',
        'lost_reason',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'priority' => LeadPriority::class,
            'follow_up_date' => 'date',
        ];
    }

    /**
     * Named `assignee`, not `assignedTo` — Eloquent snake_cases relation
     * names on serialization, and `assignedTo` → `assigned_to` would
     * collide with (and silently overwrite) the raw `assigned_to` FK
     * column in the JSON/array representation.
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function pipelineLogs(): HasMany
    {
        return $this->hasMany(PipelineLog::class)->latest('created_at');
    }

    public function project(): HasOne
    {
        return $this->hasOne(Project::class);
    }

    public function design(): HasOne
    {
        return $this->hasOne(Design::class);
    }

    public function scopeByStatus(Builder $query, ?string $status): Builder
    {
        return $query->when($status, fn (Builder $q) => $q->where('status', $status));
    }

    public function scopeByPriority(Builder $query, ?string $priority): Builder
    {
        return $query->when($priority, fn (Builder $q) => $q->where('priority', $priority));
    }

    /** Follow-up date sudah lewat dan lead belum LOST/closed — PRD §4.1 "highlight sebagai reminder". */
    public function scopeOverdueFollowUp(Builder $query): Builder
    {
        return $query->whereNotNull('follow_up_date')
            ->where('follow_up_date', '<', now()->toDateString())
            ->whereNotIn('status', [LeadStatus::Lost->value, LeadStatus::Closing->value]);
    }
}
