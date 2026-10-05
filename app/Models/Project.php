<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Enums\TerminStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'quotation_id',
        'name',
        'pm_id',
        'assistant_pm_id',
        'status',
        'start_date',
        'end_date',
        'contract_value',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'contract_value' => 'decimal:2',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** Named `pm`, not `projectManager` — matches the `pm_id` column, no snake_case collision either way. */
    public function pm(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pm_id');
    }

    /** Sprint 12 D2 — optional, chosen by the CEO at "Buka Proyek". */
    public function assistantPm(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assistant_pm_id');
    }

    /** Sprint 12 #19 — the "RAB Fix" the client approved (null on pre-Sprint-12 projects). */
    /** Sprint 12 #29 — RAB Tambahan requested on this project (any status). */
    public function addenda(): HasMany
    {
        return $this->hasMany(Quotation::class)->whereNotNull('parent_quotation_id')->orderBy('id');
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('order');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function progressLogs(): HasMany
    {
        return $this->hasMany(ProgressLog::class)->latest('log_date');
    }

    public function termins(): HasMany
    {
        return $this->hasMany(Termin::class)->orderBy('termin_number');
    }

    /**
     * Sprint 12 #22 / D2 — the project's PM or its Asisten PM ("proyek
     * yang dikelola"). Allocation & realisation stay with the PM alone
     * (ProjectPolicy::manageBudget()).
     */
    public function isManagedBy(User $user): bool
    {
        return ((int) $this->pm_id === (int) $user->id && $user->hasRole('PM'))
            || ($this->assistant_pm_id !== null && (int) $this->assistant_pm_id === (int) $user->id && $user->hasRole('ASISTEN_PM'));
    }

    /** Sprint 12 #22 — an Asisten PM's projects are the ones they're assigned to. */
    public function scopeAssistedBy(Builder $query, User $user): Builder
    {
        return $query->where('assistant_pm_id', $user->id);
    }

    /** Sprint 12 #24 — "Alokasi Dana Proyek" (ProjectBudgetService). */
    public function budgetPosts(): HasMany
    {
        return $this->hasMany(BudgetPost::class)->orderBy('sort_order')->orderBy('id');
    }

    public function projectMaterials(): HasMany
    {
        return $this->hasMany(ProjectMaterial::class);
    }

    /**
     * COMPLETED (set only by QaFormService once every milestone passed
     * QA) and CANCELLED (manual, terminal) are final — the project and
     * its task/milestone plan become read-only (Sprint 9 decision #1).
     */
    public function isClosed(): bool
    {
        return in_array($this->status, [ProjectStatus::Completed, ProjectStatus::Cancelled], true);
    }

    /**
     * Any DP/pelunasan recorded on any termin (a PAID termin always has
     * one — see the dp_amount/pelunasan migration's backfill). Once money
     * came in against the contract, its value is fixed (ProjectService::update()).
     */
    public function hasTerminPayments(): bool
    {
        return $this->termins()
            ->where(fn (Builder $q) => $q->where('dp_amount', '>', 0)
                ->orWhere('pelunasan', '>', 0)
                ->orWhere('status', TerminStatus::Paid->value))
            ->exists();
    }

    public function scopeByStatus(Builder $query, ?string $status): Builder
    {
        return $query->when($status, fn (Builder $q) => $q->where('status', $status));
    }

    public function scopeByPm(Builder $query, ?int $pmId): Builder
    {
        return $query->when($pmId, fn (Builder $q) => $q->where('pm_id', $pmId));
    }

    /** Only ACTIVE projects feed the automation (penalties, overdue flags) — ON_HOLD/closed ones are paused. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ProjectStatus::Active->value);
    }
}
