<?php

namespace App\Models;

use App\Enums\SiteFindingStatus;
use App\Enums\WorkItemQaStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sprint 29 Sub 08 (B2) — a RAB item as work of the project: the Satuan
 * Upah the Pengawas set and its Kepala Tim. The money rules live in
 * WorkItemService only.
 */
class ProjectWorkItem extends Model
{
    protected $fillable = [
        'project_id',
        'quotation_item_id',
        'team_lead_id',
        'team_lead_name',
        'unit_wage',
        'production_start',
        'production_end',
        'installation_start',
        'installation_end',
        'set_by',
    ];

    protected function casts(): array
    {
        return [
            'unit_wage' => 'decimal:2',
            // Sprint 31 Sub 03 — jadwal per item (File C, light-blue cells).
            'production_start' => 'date',
            'production_end' => 'date',
            'installation_start' => 'date',
            'installation_end' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function quotationItem(): BelongsTo
    {
        return $this->belongsTo(QuotationItem::class);
    }

    public function teamLead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'team_lead_id');
    }

    /** Sprint 29 Sub 12 — QA decisions on this item, append-only (the newest one counts). */
    public function qaChecks(): HasMany
    {
        return $this->hasMany(WorkItemQaCheck::class);
    }

    /**
     * Sprint 29 Sub 12 (G1) — items waiting for the Pengawas's QA: with a
     * wage, at 100 % in the pengecekan, and whose newest QA check is neither
     * LOLOS nor a PERBAIKAN whose finding is still open (a closed finding =
     * repaired → QA again). The SQL twin of WorkItemQaService::statuses().
     */
    public function scopeAwaitingQa(Builder $query): Builder
    {
        return $query
            ->where('project_work_items.unit_wage', '>', 0)
            ->whereRaw('(select coalesce(sum(p.percent), 0) from work_item_progress p where p.project_work_item_id = project_work_items.id) >= 100')
            ->whereNotExists(fn ($latest) => $latest
                ->selectRaw('1')
                ->from('work_item_qa_checks as c')
                ->whereColumn('c.project_work_item_id', 'project_work_items.id')
                ->whereRaw('c.id = (select max(c2.id) from work_item_qa_checks c2 where c2.project_work_item_id = project_work_items.id)')
                ->where(fn ($settled) => $settled
                    ->where('c.decision', WorkItemQaStatus::Lolos->value)
                    ->orWhereExists(fn ($finding) => $finding
                        ->selectRaw('1')
                        ->from('site_findings')
                        ->whereColumn('site_findings.id', 'c.site_finding_id')
                        ->where('site_findings.status', SiteFindingStatus::Terbuka->value))));
    }

    /**
     * Sprint 31 Sub 03 — items behind their schedule: with a wage, below
     * 100 %, and the last window (installation, else production) ended
     * before `$today`. The SQL twin of WorkItemService::scheduleStatus().
     */
    public function scopeBehindSchedule(Builder $query, string $today): Builder
    {
        return $query
            ->where('project_work_items.unit_wage', '>', 0)
            ->whereRaw('COALESCE(project_work_items.installation_end, project_work_items.production_end) < ?', [$today])
            ->whereRaw('(select coalesce(sum(p.percent), 0) from work_item_progress p where p.project_work_item_id = project_work_items.id) < 100');
    }

    /** "Putra" — the Kepala Tim's name, whichever way it was set. */
    public function teamLeadLabel(): ?string
    {
        return $this->teamLead?->name ?? $this->team_lead_name;
    }

    public function hasWage(): bool
    {
        return $this->unit_wage !== null && (float) $this->unit_wage > 0;
    }
}
