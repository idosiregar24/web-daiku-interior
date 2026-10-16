<?php

namespace App\Models;

use App\Enums\ProjectPhase;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Enums\TerminStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'quotation_id',
        'name',
        'pm_id',
        'assistant_pm_id',
        'team_lead_id',
        'team_lead_name',
        'supervisor_id',
        'opened_on',
        'status',
        'phase',
        'phase_changed_at',
        'production_started_at',
        'progress_70_notified_at',
        'departure_approved_by',
        'departure_approved_at',
        'start_date',
        'end_date',
        'contract_value',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'phase' => ProjectPhase::class,
            'phase_changed_at' => 'datetime',
            'production_started_at' => 'datetime',
            'progress_70_notified_at' => 'datetime',
            'departure_approved_at' => 'datetime',
            'opened_on' => 'date',
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

    /** Sprint 12 D2 — optional, chosen at "Buka Proyek" (by the approving PM since Sprint 23 K13). */
    public function assistantPm(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assistant_pm_id');
    }

    /**
     * Sprint 23 K14/K15 — the Kepala Tim when it is a user; an outsider or
     * vendor is `team_lead_name` instead (exactly one of the two since
     * Sprint 23 — see teamLeadLabel()).
     */
    public function teamLead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'team_lead_id');
    }

    /** Sprint 23 K14/K15 — the Pengawas (QA/QC), a user with role QA; its work is Sprint 24's (D3–D12). */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    /** Sprint 24 D14 — the user is this project's Pengawas (QA/QC). */
    public function isSupervisedBy(User $user): bool
    {
        return $this->supervisor_id !== null && (int) $this->supervisor_id === (int) $user->id && $user->hasRole('QA');
    }

    /**
     * Sprint 24 D14 — Pengawas = QA = QC: the project's Pengawas checks its
     * timelines; a project without a Pengawas (older ones) is open to every
     * user with role QA.
     */
    public function isQaReviewableBy(User $user): bool
    {
        return $this->supervisor_id === null ? $user->hasRole('QA') : $this->isSupervisedBy($user);
    }

    /** Query twin of isQaReviewableBy(): projects this Pengawas checks. */
    public function scopeQaReviewableBy(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('supervisor_id', $user->id)->orWhereNull('supervisor_id'));
    }

    /** Sprint 24 D2 — the project's Gambar Kerja (null on projects opened before Sprint 24). */
    public function workingDrawing(): HasOne
    {
        return $this->hasOne(WorkingDrawing::class);
    }

    /** Sprint 24 D3 — the Technical Meeting. */
    public function technicalMeeting(): HasOne
    {
        return $this->hasOne(TechnicalMeeting::class);
    }

    /** Sprint 24 D4 — Jasa Pengawas (set at the TM). */
    public function supervisorFee(): HasOne
    {
        return $this->hasOne(SupervisorFee::class);
    }

    public function supervisorPayments(): HasMany
    {
        return $this->hasMany(SupervisorPayment::class)->orderBy('id');
    }

    /** Sprint 24 D10 — the Pengawas's Friday reports. */
    public function weeklyReports(): HasMany
    {
        return $this->hasMany(WeeklyReport::class)->orderByDesc('week_start');
    }

    /** Sprint 24 D12 — finishing findings on site. */
    public function siteFindings(): HasMany
    {
        return $this->hasMany(SiteFinding::class)->latest('id');
    }

    public function departureApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'departure_approved_by');
    }

    /**
     * Sprint 24 D11/E5 — progress = "actual activity": DONE tasks ÷ all
     * tasks of the project, every task weighing the same. 0 without tasks.
     */
    public function actualProgress(): int
    {
        $counts = $this->tasks()
            ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS done', [TaskStatus::Done->value])
            ->toBase()
            ->first();

        $total = (int) ($counts->total ?? 0);

        return $total === 0 ? 0 : (int) floor(((int) $counts->done) * 100 / $total);
    }

    /** The phase, treating a null (never on a real row — the column defaults to PRODUKSI) as PRODUKSI. */
    public function currentPhase(): ProjectPhase
    {
        return $this->phase ?? ProjectPhase::Produksi;
    }

    /** "Budi" (user) or "CV Kayu Jati" (typed) — null on a project that has neither yet. */
    public function teamLeadLabel(): ?string
    {
        return $this->teamLead?->name ?? $this->team_lead_name;
    }

    /** Sprint 12 #29 — RAB Tambahan requested on this project (any status). */
    public function addenda(): HasMany
    {
        return $this->hasMany(Quotation::class)->whereNotNull('parent_quotation_id')->orderBy('id');
    }

    /** Sprint 12 #19 — the "RAB Fix" the client approved (null on pre-Sprint-12 projects). */
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

    public function qaForms(): HasMany
    {
        return $this->hasMany(QaForm::class);
    }

    public function overtimeRequests(): HasMany
    {
        return $this->hasMany(OvertimeRequest::class);
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

    /**
     * The projects a user may list (the query twin of ProjectPolicy::view()
     * for lists): a Field Staff only the projects with a task of theirs,
     * an Asisten PM only the ones assigned to them (Sprint 12 #22),
     * everyone else every project. Shared by the project list and the
     * global search (Sprint 13).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query
            ->when(
                $user->hasRole('FIELD_STAFF') && ! $user->hasAnyRole(['CEO', 'SUPERADMIN']),
                fn (Builder $q) => $q->whereHas('tasks', fn (Builder $task) => $task->where('assignee_id', $user->id)),
            )
            ->when(
                $user->hasRole('ASISTEN_PM') && ! $user->hasAnyRole(['CEO', 'PM', 'SUPERADMIN']),
                fn (Builder $q) => $q->assistedBy($user),
            );
    }

    /** The query twin of isManagedBy(): projects a PM leads or an Asisten PM is assigned to. */
    public function scopeManagedBy(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->when($user->hasRole('PM'), fn (Builder $q) => $q->orWhere('pm_id', $user->id))
            ->when($user->hasRole('ASISTEN_PM'), fn (Builder $q) => $q->orWhere('assistant_pm_id', $user->id))
            // Neither role: nothing (an empty OR group would match everything).
            ->when(! $user->hasAnyRole(['PM', 'ASISTEN_PM']), fn (Builder $q) => $q->whereRaw('1 = 0')));
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
