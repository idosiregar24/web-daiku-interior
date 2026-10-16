<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\ProjectPhase;
use App\Enums\SiteFindingStatus;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\SiteFinding;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 24 D12 — "Pengecekan finishing di lokasi". At PEMASANGAN the
 * Pengawas records what is still wrong; each finding can become a repair
 * task for a Tukang ("Jadikan Task Perbaikan"), and closes when that task
 * is DONE (TaskService::updateStatus()) or when the Pengawas / PM closes it.
 * An open finding holds its timeline's QA approval (QaFormService) and the
 * project's completion (ProjectService::completeIfFinished()).
 */
class SiteFindingService
{
    public function __construct(
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
        private TaskService $taskService,
    ) {}

    /** @param  array{description: string, milestone_id?: int|string|null, photo_url?: ?string}  $data */
    public function record(Project $project, array $data, User $actor): SiteFinding
    {
        if ($project->isClosed() || $project->currentPhase() !== ProjectPhase::Pemasangan) {
            throw ValidationException::withMessages(['description' => 'Temuan finishing dicatat saat proyek di tahap Pemasangan di lokasi.']);
        }

        $milestoneId = filled($data['milestone_id'] ?? null) ? (int) $data['milestone_id'] : null;

        if ($milestoneId && ! Milestone::whereKey($milestoneId)->where('project_id', $project->id)->exists()) {
            throw ValidationException::withMessages(['milestone_id' => 'Timeline harus milik proyek ini.']);
        }

        $finding = SiteFinding::create([
            'project_id' => $project->id,
            'milestone_id' => $milestoneId,
            'description' => trim($data['description']),
            'photo_url' => filled($data['photo_url'] ?? null) ? trim($data['photo_url']) : null,
            'status' => SiteFindingStatus::Terbuka->value,
            'reported_by' => $actor->id,
        ]);

        $this->auditLogService->record('site_finding.recorded', $finding, null, $finding->only(['milestone_id', 'description', 'photo_url']), $actor);

        $this->notificationService->notifyMany(
            collect([$project->pm, $project->assistantPm])->filter(fn (?User $user) => $user && $user->id !== $actor->id),
            NotificationType::SiteFindingRecorded,
            'Temuan Finishing di Lokasi',
            "{$actor->name} mencatat temuan di proyek \"{$project->name}\": ".str($finding->description)->limit(100),
            ['project_id' => $project->id],
        );

        return $finding;
    }

    /**
     * "Jadikan Task Perbaikan" — a task for a Tukang in the finding's
     * timeline (or the project's last one); allowed after the timeline's end.
     *
     * @param  array{assignee_id: int|string, due_date: string, title?: ?string, rate_per_task?: numeric-string|int|float|null}  $data
     */
    public function makeRepairTask(SiteFinding $finding, array $data, User $actor): SiteFinding
    {
        return DB::transaction(function () use ($finding, $data, $actor) {
            $finding = SiteFinding::lockForUpdate()->with('project')->findOrFail($finding->id);

            if ($finding->status !== SiteFindingStatus::Terbuka) {
                throw ValidationException::withMessages(['assignee_id' => 'Temuan ini sudah selesai.']);
            }

            if ($finding->task_id) {
                throw ValidationException::withMessages(['assignee_id' => 'Temuan ini sudah punya task perbaikan.']);
            }

            $milestoneId = $finding->milestone_id ?? $finding->project->milestones()->reorder()->orderByDesc('order')->orderByDesc('target_date')->value('id');

            $task = $this->taskService->create($finding->project, [
                'milestone_id' => $milestoneId,
                'title' => filled($data['title'] ?? null) ? $data['title'] : 'Perbaikan: '.str($finding->description)->limit(120, '…'),
                'description' => $finding->description.($finding->photo_url ? "\nFoto: {$finding->photo_url}" : ''),
                'assignee_id' => $data['assignee_id'],
                'due_date' => $data['due_date'],
                'priority' => 'HIGH',
                'rate_per_task' => $data['rate_per_task'] ?? null,
            ], $actor, repair: true);

            $finding->update(['task_id' => $task->id]);
            $this->auditLogService->record('site_finding.repair_task', $finding, null, ['task_id' => $task->id, 'assignee_id' => (int) $data['assignee_id']], $actor);

            return $finding;
        });
    }

    /** Closed by hand (Pengawas / PM) — e.g. fixed on the spot without a task. */
    public function resolve(SiteFinding $finding, User $actor): SiteFinding
    {
        if ($finding->status !== SiteFindingStatus::Terbuka) {
            throw ValidationException::withMessages(['finding' => 'Temuan ini sudah selesai.']);
        }

        $finding->update(['status' => SiteFindingStatus::Selesai->value, 'resolved_by' => $actor->id, 'resolved_at' => now()]);
        $this->auditLogService->record('site_finding.resolved', $finding, ['status' => SiteFindingStatus::Terbuka->value], ['status' => SiteFindingStatus::Selesai->value], $actor);

        return $finding;
    }

    /** Open findings that hold this timeline's QA: its own, plus the project-wide ones (no timeline). */
    public static function openFor(Milestone $milestone): int
    {
        return SiteFinding::query()
            ->open()
            ->where('project_id', $milestone->project_id)
            ->where(fn ($query) => $query->whereNull('milestone_id')->orWhere('milestone_id', $milestone->id))
            ->count();
    }
}
