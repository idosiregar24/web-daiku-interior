<?php

namespace App\Services;

use App\Enums\MilestoneStatus;
use App\Models\Milestone;
use App\Models\Project;
use Illuminate\Validation\ValidationException;

class MilestoneService
{
    public function __construct(private QaFormService $qaFormService) {}

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
        if (! in_array($milestone->status, [MilestoneStatus::Pending, MilestoneStatus::InProgress], true)) {
            throw ValidationException::withMessages([
                'status' => 'Milestone ini sudah menunggu QA atau sudah selesai.',
            ]);
        }

        $milestone->update(['status' => MilestoneStatus::QaWaiting->value]);

        if (! $milestone->qaForm()->exists()) {
            $this->qaFormService->createForMilestone($milestone);
        }

        return $milestone->fresh();
    }
}
