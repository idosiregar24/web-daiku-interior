<?php

namespace App\Services;

use App\Enums\ProjectPhase;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 24 D1 — the one place a project's execution phase moves. Each
 * step is triggered by the action that finishes the previous one
 * (WorkingDrawingService::complete() → TM, TechnicalMeetingService::complete()
 * → PERSIAPAN, the PM approving the Pengawas's DP → PRODUKSI, "Setujui
 * Berangkat ke Lokasi" → PEMASANGAN), only ever forward and one step at a
 * time, and audited as `project.phase_changed`.
 */
class ProjectPhaseService
{
    public function __construct(private AuditLogService $auditLogService) {}

    /** Moves `$project` from `$from` to the next phase; refused when it isn't in `$from` (any more). */
    public function advance(Project $project, ProjectPhase $from, ProjectPhase $to, ?User $actor = null): Project
    {
        if ($project->status !== ProjectStatus::Active) {
            throw ValidationException::withMessages(['phase' => 'Proyek tidak sedang berjalan — tahapnya tidak bisa dilanjutkan.']);
        }

        if ($project->currentPhase() !== $from || $to->rank() !== $from->rank() + 1) {
            throw ValidationException::withMessages([
                'phase' => "Proyek ini sedang di tahap {$project->currentPhase()->label()} — langkah ini tidak berlaku sekarang.",
            ]);
        }

        $project->update([
            'phase' => $to->value,
            'phase_changed_at' => now(),
            ...($to === ProjectPhase::Produksi ? ['production_started_at' => now()] : []),
        ]);

        $this->auditLogService->record('project.phase_changed', $project, ['phase' => $from->value], ['phase' => $to->value], $actor);

        return $project;
    }

    /** Throws unless the project is (still) running and in `$phase` — the guard of each phase's own actions. */
    public function ensurePhase(Project $project, ProjectPhase $phase, string $message): void
    {
        if ($project->status !== ProjectStatus::Active || $project->currentPhase() !== $phase) {
            throw ValidationException::withMessages(['phase' => $message]);
        }
    }
}
