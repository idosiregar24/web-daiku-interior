<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 32 — one Kepala Tim's people on one project (`team_label` = the
 * label of the payments' breakdown), copied from a WorkTeam template and
 * adjustable. Read by WageSplitService::slips() — a help for the Kepala
 * Tim, never a payment.
 */
class ProjectWageSplit extends Model
{
    protected $fillable = ['project_id', 'team_label', 'work_team_id', 'members', 'updated_by'];

    protected function casts(): array
    {
        return [
            'members' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workTeam(): BelongsTo
    {
        return $this->belongsTo(WorkTeam::class);
    }
}
