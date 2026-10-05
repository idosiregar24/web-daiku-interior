<?php

namespace App\Models;

use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use App\Enums\QuotationType;
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
        'first_contacted_at',
        'source',
        'lead_source_id',
        'priority',
        'category',
        'lead_category_id',
        'service',
        'city',
        'address',
        'maps_url',
        'gender',
        'order_detail',
        'status',
        'assigned_to',
        'lost_reason',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'priority' => LeadPriority::class,
            'first_contacted_at' => 'date:Y-m-d',
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

    /**
     * Data Master rows (SuperAdmin-editable). The legacy `source` /
     * `category` string columns are kept in sync with these rows' `name`
     * by LeadService, so older readers that group/display by the string
     * keep working during the transition.
     */
    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    public function leadCategory(): BelongsTo
    {
        return $this->belongsTo(LeadCategory::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function pipelineLogs(): HasMany
    {
        return $this->hasMany(PipelineLog::class)->latest('created_at');
    }

    /** Sprint 12 decision #2 — FU-1, FU-2, … in order. */
    public function followUps(): HasMany
    {
        return $this->hasMany(LeadFollowUp::class)->orderBy('sequence');
    }

    /** Sprint 12 decision #3 — site surveys, possibly repeated. */
    public function surveys(): HasMany
    {
        return $this->hasMany(LeadSurvey::class)->orderBy('sequence');
    }

    public function project(): HasOne
    {
        return $this->hasOne(Project::class);
    }

    public function design(): HasOne
    {
        return $this->hasOne(Design::class);
    }

    /**
     * The project RAB (Sprint 12: a lead has several quotations — Jasa
     * Survey, Jasa Desain, Proyek). Kept as `quotation` because every
     * pre-Sprint-12 reader (deal confirmation, project creation, design
     * sync, analytics) means the project offer.
     */
    public function quotation(): HasOne
    {
        // Newest first: a has-one keeps the first row per lead, eager-loaded or not.
        return $this->hasOne(Quotation::class)
            ->where('quotations.type', QuotationType::Proyek->value)
            ->orderByDesc('quotations.id');
    }

    /** Sprint 12 decision #6 — every quotation of the lead, any type. */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class)->latest('id');
    }

    public function scopeByStatus(Builder $query, ?string $status): Builder
    {
        return $query->when($status, fn (Builder $q) => $q->where('status', $status));
    }

    public function scopeByPriority(Builder $query, ?string $priority): Builder
    {
        return $query->when($priority, fn (Builder $q) => $q->where('priority', $priority));
    }

    public function scopeByLeadSource(Builder $query, ?int $leadSourceId): Builder
    {
        return $query->when($leadSourceId, fn (Builder $q) => $q->where('lead_source_id', $leadSourceId));
    }

    public function scopeByLeadCategory(Builder $query, ?int $leadCategoryId): Builder
    {
        return $query->when($leadCategoryId, fn (Builder $q) => $q->where('lead_category_id', $leadCategoryId));
    }

    /**
     * An open follow-up whose date already passed, on a lead not yet
     * LOST/closed — PRD §4.1 "highlight sebagai reminder" (Sprint 12: read
     * from lead_follow_ups).
     */
    public function scopeOverdueFollowUp(Builder $query): Builder
    {
        return $query->whereNotIn('status', [LeadStatus::Lost->value, LeadStatus::Closing->value])
            ->whereHas('followUps', fn (Builder $q) => $q->pending()->where('scheduled_date', '<', now()->toDateString()));
    }

    /**
     * Adds `next_follow_up_date` (earliest open follow-up) and
     * `follow_ups_count` — what lists and the dashboard show per lead.
     */
    public function scopeWithNextFollowUp(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('leads.*');
        }

        return $query
            ->addSelect(['next_follow_up_date' => LeadFollowUp::query()
                ->selectRaw('MIN(scheduled_date)')
                ->whereColumn('lead_follow_ups.lead_id', 'leads.id')
                ->whereNull('done_at')])
            ->withCount('followUps');
    }
}
