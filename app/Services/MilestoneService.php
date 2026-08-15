<?php

namespace App\Services;

use App\Models\Milestone;
use App\Models\Project;

class MilestoneService
{
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
}
