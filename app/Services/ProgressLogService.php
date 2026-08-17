<?php

namespace App\Services;

use App\Enums\MilestoneStatus;
use App\Models\ProgressLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * PRD §4.4 "Progress Log" + business rule "Progress baru bisa di-input
 * jika QA milestone sebelumnya sudah APPROVED" — enforced here as "no
 * milestone on this project may currently be QA_WAITING", equivalent for
 * the sequential milestone state machine (only one milestone is ever
 * QA_WAITING at a time — see MilestoneService::markDone()).
 */
class ProgressLogService
{
    public function create(Project $project, array $data, User $actor): ProgressLog
    {
        if ($project->milestones()->where('status', MilestoneStatus::QaWaiting->value)->exists()) {
            throw ValidationException::withMessages([
                'percentage' => 'Progress belum bisa diinput — masih ada milestone menunggu review QA.',
            ]);
        }

        return $project->progressLogs()->create([
            'logged_by' => $actor->id,
            'percentage' => $data['percentage'],
            'description' => $data['description'],
            'ref_urls' => $data['ref_urls'] ?? null,
            'log_date' => $data['log_date'] ?? now()->toDateString(),
        ]);
    }
}
