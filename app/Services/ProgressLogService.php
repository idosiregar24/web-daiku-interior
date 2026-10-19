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
 *
 * Sprint 24 D11 — a log is a note now ("Catatan Progress"); the progress
 * percentage is the actual activity (Project::actualProgress()).
 */
class ProgressLogService
{
    public function create(Project $project, array $data, User $actor): ProgressLog
    {
        if ($project->milestones()->where('status', MilestoneStatus::QaWaiting->value)->exists()) {
            throw ValidationException::withMessages([
                'description' => 'Catatan progress belum bisa ditambahkan — masih ada timeline menunggu pemeriksaan Pengawas (QA/QC).',
            ]);
        }

        return $project->progressLogs()->create([
            'logged_by' => $actor->id,
            'description' => $data['description'],
            'ref_urls' => $data['ref_urls'] ?? null,
            'log_date' => $data['log_date'] ?? now()->toDateString(),
        ]);
    }
}
