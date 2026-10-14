<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A RAB Proyek the client approved, waiting for "Buka Proyek"
 * (QueueProjectOpening → ProjectService::openFromQuotation()).
 *
 * Sprint 23 K13 — opened by the PM who approved that RAB (`opener_id`,
 * stored when queued); null when an Asisten PM approved it (or it predates
 * the flow) — then any PM opens. A designated PM who is no longer an
 * active PM hands it to every PM too, so an opening is never stuck.
 * The CEO no longer opens (Sprint 12 #19 is replaced).
 */
class ProjectOpening extends Model
{
    public const STATUS_WAITING = 'MENUNGGU_PM';

    /** @deprecated Sprint 12 waiting status (the CEO opened) — migrated to MENUNGGU_PM, still read as waiting. */
    public const STATUS_WAITING_LEGACY = 'MENUNGGU_CEO';

    public const STATUS_OPENED = 'DIBUKA';

    protected $fillable = ['quotation_id', 'lead_id', 'status', 'opener_id', 'opened_by', 'opened_at', 'project_id'];

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

    /** Sprint 23 K13 — the PM who approved the RAB Proyek (null = any PM). */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opener_id');
    }

    /**
     * Sprint 23 K13 — the PM who approved the version the client accepted
     * (its PM-stage `quotation_approvals` row), or null when the approver
     * isn't a PM (an Asisten PM, Sprint 12 D2) or there is no such row.
     */
    public static function approvingPmOf(Quotation $quotation): ?User
    {
        $approver = QuotationApproval::query()
            ->where('quotation_id', $quotation->id)
            ->where('version', $quotation->version)
            ->where('approver_role', QuotationItemReview::STAGE_PM)
            ->where('status', 'APPROVED')
            ->latest('id')
            ->first()
            ?->approver;

        return $approver && $approver->is_active && $approver->hasRole('PM') ? $approver : null;
    }

    public function isWaiting(): bool
    {
        return in_array($this->status, [self::STATUS_WAITING, self::STATUS_WAITING_LEGACY], true);
    }

    /**
     * Whether `$user` is the one to open it: a PM, and either the
     * designated one or — no designated PM / they are no longer an active
     * PM — any PM. The query twin is scopeOpenableBy().
     */
    public function canBeOpenedBy(User $user): bool
    {
        if (! $user->hasRole('PM')) {
            return false;
        }

        if ($this->opener_id === null || (int) $this->opener_id === (int) $user->id) {
            return true;
        }

        $opener = $this->opener;

        return ! $opener || ! $opener->is_active || ! $opener->hasRole('PM');
    }

    public function scopeWaiting(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_WAITING, self::STATUS_WAITING_LEGACY]);
    }

    /** The openings `$user` may open (see canBeOpenedBy()); nothing for a non-PM. */
    public function scopeOpenableBy(Builder $query, User $user): Builder
    {
        if (! $user->hasRole('PM')) {
            return $query->whereRaw('1 = 0');
        }

        // Mirrors canBeOpenedBy(): mine, nobody's, or a designated PM who no longer is one.
        return $query->where(fn (Builder $q) => $q
            ->whereNull('opener_id')
            ->orWhere('opener_id', $user->id)
            ->orWhereHas('opener', fn (Builder $opener) => $opener
                ->where('is_active', false)
                ->orWhereDoesntHave('roles', fn (Builder $role) => $role->where('name', 'PM'))));
    }
}
