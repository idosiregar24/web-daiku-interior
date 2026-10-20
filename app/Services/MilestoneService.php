<?php

namespace App\Services;

use App\Enums\MilestoneStatus;
use App\Enums\NotificationType;
use App\Models\Milestone;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class MilestoneService
{
    public function __construct(
        private QaFormService $qaFormService,
        private NotificationService $notificationService,
    ) {}

    /**
     * CSV Sprint 6 "Milestone status auto-update: OVERDUE jika lewat
     * targetDate (scheduler)" — PRD §4.4 status list. Only milestones
     * still being worked on (PENDING/IN_PROGRESS) can go overdue: one
     * already handed to QA (QA_WAITING) is waiting on QA, not the team.
     * Only ACTIVE projects — an ON_HOLD/COMPLETED/CANCELLED project's
     * schedule is paused (Sprint 9 decision #1). The status change is its
     * own idempotency guard, and the PM gets one notification per project
     * per run.
     */
    public function markOverdueMilestones(): int
    {
        $overdue = Milestone::query()
            ->whereIn('status', [MilestoneStatus::Pending->value, MilestoneStatus::InProgress->value])
            ->whereDate('target_date', '<', now('Asia/Jakarta')->toDateString())
            ->whereHas('project', fn ($query) => $query->active())
            ->with('project.pm')
            ->get();

        if ($overdue->isEmpty()) {
            return 0;
        }

        Milestone::whereKey($overdue->modelKeys())->update(['status' => MilestoneStatus::Overdue->value]);

        $overdue->groupBy('project_id')->each(function ($milestones) {
            $project = $milestones->first()->project;

            $this->notificationService->notifyMany(
                [$project->pm],
                NotificationType::MilestoneOverdue,
                'Timeline Melewati Tanggal Selesai',
                'Timeline '.$milestones->pluck('name')->map(fn ($name) => "\"{$name}\"")->implode(', ')
                    ." di proyek \"{$project->name}\" melewati tanggal selesainya.",
                ['project_id' => $project->id],
            );
        });

        return $overdue->count();
    }

    /**
     * New milestones append to the end of the project's order — PM
     * reorders explicitly afterward (via `reorder()`), never by guessing
     * an `order` value on create. A COMPLETED/CANCELLED project's plan is
     * final (Sprint 9 decision #1).
     *
     * Sprint 23 K17 — the form always sends `start_date` (required by
     * StoreMilestoneRequest); an internal caller without one gets the same
     * default the migration backfilled: the day after the last timeline's
     * end, or the project's start for the first — never after its own end.
     *
     * @param  array{name: string, start_date?: string|null, target_date: string}  $data
     */
    public function create(Project $project, array $data): Milestone
    {
        $this->ensureProjectOpen($project);

        $nextOrder = $project->milestones()->max('order') + 1;

        return $project->milestones()->create([
            ...$data,
            'start_date' => $data['start_date'] ?? $this->defaultStartDate($project, $data['target_date']),
            'order' => $nextOrder,
        ]);
    }

    public function update(Milestone $milestone, array $data): Milestone
    {
        $milestone->update($data);

        return $milestone;
    }

    /**
     * Persist a full new ordering for a project's milestones in one go —
     * `$orderedIds` is the milestone ID list in its new display order.
     */
    public function reorder(Project $project, array $orderedIds): void
    {
        foreach (array_values($orderedIds) as $index => $milestoneId) {
            $project->milestones()->whereKey($milestoneId)->update(['order' => $index]);
        }
    }

    /**
     * PRD §4.6/§6.3 — PM "marks a milestone done", which does NOT set it
     * to COMPLETED directly; it opens QA review (QA_WAITING) and
     * auto-creates the QaForm (PRD: "QA Form dibuat otomatis oleh
     * sistem"). The milestone only actually becomes COMPLETED once QA
     * approves (QaFormService::review()). Re-callable after a QA reject
     * sent it back to IN_PROGRESS — reuses the existing QaForm (unique
     * per milestone) rather than creating a second one. Not on a
     * cancelled project — that would only queue QA work nobody needs.
     */
    public function markDone(Milestone $milestone): Milestone
    {
        $this->ensureProjectOpen($milestone->project);

        // OVERDUE is still finishable — it only marks a missed target date
        // (markOverdueMilestones()), the work can still be handed to QA.
        if (! in_array($milestone->status, [MilestoneStatus::Pending, MilestoneStatus::InProgress, MilestoneStatus::Overdue], true)) {
            throw ValidationException::withMessages([
                'status' => 'Timeline ini sudah menunggu pemeriksaan Pengawas (QA/QC) atau sudah selesai.',
            ]);
        }

        $milestone->update(['status' => MilestoneStatus::QaWaiting->value]);

        if ($qaForm = $milestone->qaForm) {
            $this->qaFormService->resubmit($qaForm);
        } else {
            $this->qaFormService->createForMilestone($milestone);
        }

        return $milestone->fresh();
    }

    /**
     * Sprint 28 K9 — Start Produksi: the project's first timeline not yet
     * completed (in the project's `order`) is IN_PROGRESS — same step as
     * QaFormService::advanceNextMilestone() after a QA approval. Only a
     * PENDING one moves; one already running, overdue or with QA is left
     * as it is. Runs inside the caller's transaction.
     */
    public function startFirstPending(Project $project): ?Milestone
    {
        $first = $project->milestones()
            ->where('status', '!=', MilestoneStatus::Completed->value)
            ->reorder()
            ->orderBy('order')
            ->orderBy('id')
            ->first();

        if ($first && $first->status === MilestoneStatus::Pending) {
            $first->update(['status' => MilestoneStatus::InProgress->value]);
        }

        return $first;
    }

    private function defaultStartDate(Project $project, string $targetDate): string
    {
        $previous = $project->milestones()->reorder()->orderByDesc('order')->orderByDesc('target_date')->orderByDesc('id')->first();
        $end = Carbon::parse($targetDate)->startOfDay();
        $start = match (true) {
            $previous !== null => $previous->target_date->copy()->addDay(),
            $project->start_date !== null => $project->start_date->copy(),
            default => $end->copy(),
        };

        return $start->min($end)->toDateString();
    }

    private function ensureProjectOpen(Project $project): void
    {
        if ($project->isClosed()) {
            throw ValidationException::withMessages([
                'project' => "Proyek \"{$project->name}\" sudah {$project->status->value} — timeline-nya tidak bisa ditambah atau diajukan ke QA lagi.",
            ]);
        }
    }
}
