<?php

namespace App\Services;

use App\Models\ProgressLog;
use App\Models\Project;
use App\Models\User;

/**
 * PRD §4.4 "Progress Log", shown as "Catatan Progress" — since Sprint 24
 * D11 a PM's note only; the progress itself is the actual activity
 * (Project::actualProgress()). Sprint 25 Q5 dropped PRD §4.4's "progress
 * only after the previous milestone's QA is APPROVED" block: with no typed
 * percentage there is no progress claim left to hold back.
 */
class ProgressLogService
{
    public function create(Project $project, array $data, User $actor): ProgressLog
    {
        return $project->progressLogs()->create([
            'logged_by' => $actor->id,
            'description' => $data['description'],
            'ref_urls' => $data['ref_urls'] ?? null,
            'log_date' => $data['log_date'] ?? now()->toDateString(),
        ]);
    }
}
