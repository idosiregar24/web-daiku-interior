<?php

namespace App\Services;

use App\Enums\MilestoneStatus;
use App\Models\Milestone;
use App\Models\Project;
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
     * The status change is its own idempotency guard, and the PM gets
     * one notification per project per run.
     */
    public function markOverdueMilestones(): int
    {
        $overdue = Milestone::query()
            ->whereIn('status', [MilestoneStatus::Pending->value, MilestoneStatus::InProgress->value])
            ->whereDate('target_date', '<', now('Asia/Jakarta')->toDateString())
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
                'milestone_overdue',
                'Milestone Melewati Target',
                'Milestone '.$milestones->pluck('name')->map(fn ($name) => "\"{$name}\"")->implode(', ')
                    ." di proyek \"{$project->name}\" melewati target tanggal.",
                ['project_id' => $project->id],
            );
        });

        return $overdue->count();
    }

    /**
     * New milestones append to the end of the project's order — PM
     * reorders explicitly afterward (via `reorder()`), never by guessing
     * an `order` value on create.
     */
    public function create(Project $project, array $data): Milestone
    {
        $nextOrder = $project->milestones()->max('order') + 1;

        return $project->milestones()->create([
            ...$data,
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
     * per milestone) rather than creating a second one.
     */
    public function markDone(Milestone $milestone): Milestone
    {
        // OVERDUE is still finishable — it only marks a missed target date
        // (markOverdueMilestones()), the work can still be handed to QA.
        if (! in_array($milestone->status, [MilestoneStatus::Pending, MilestoneStatus::InProgress, MilestoneStatus::Overdue], true)) {
            throw ValidationException::withMessages([
                'status' => 'Milestone ini sudah menunggu QA atau sudah selesai.',
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
}
