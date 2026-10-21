<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'set_by',
    ];

    protected function casts(): array
    {
        return [
            'unit_wage' => 'decimal:2',
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
