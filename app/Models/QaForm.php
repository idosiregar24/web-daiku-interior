<?php

namespace App\Models;

use App\Enums\QaStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRD §4.6 — one per milestone (DB-unique), auto-created by
 * MilestoneService::markDone(), never by a controller directly (PRD:
 * "QA Form dibuat otomatis oleh sistem, bukan oleh PM atau QA"). No
 * `updated_at` column (see the migration) despite being reviewed
 * possibly more than once — `reviewed_at` is the "last decided" marker.
 */
class QaForm extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'milestone_id',
        'reviewer_id',
        'status',
        'checklist_data',
        'rejection_count',
        'notes',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => QaStatus::class,
            'checklist_data' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
