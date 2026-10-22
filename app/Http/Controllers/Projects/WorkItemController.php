<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\SaveWorkCheckDraftRequest;
use App\Http\Requests\Projects\SaveWorkItemWagesRequest;
use App\Http\Requests\Projects\UpdateWorkItemRequest;
use App\Models\Project;
use App\Models\ProjectWorkItem;
use App\Services\WorkItemService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sprint 29 Sub 08 (B3) — the "Upah Tukang" card's saves: the project's
 * Pengawas or the Admin Proyek (ProjectPolicy::setWages()). Inline input —
 * the page reloads only `execution` (lib/projectReload.ts).
 */
class WorkItemController extends Controller
{
    public function update(UpdateWorkItemRequest $request, ProjectWorkItem $workItem, WorkItemService $service): RedirectResponse
    {
        $this->authorize('setWages', $workItem->project);
        $service->update($workItem, $request->validated(), $request->user());

        return back()->with('success', 'Upah item tersimpan.');
    }

    /** Sprint 33 — the Upah Tukang table's one "Simpan" (every changed Satuan Upah). */
    public function saveWages(SaveWorkItemWagesRequest $request, Project $project, WorkItemService $service): RedirectResponse
    {
        $this->authorize('setWages', $project);
        $changed = $service->saveWages($project, $request->validated('items'), $request->user());

        return back()->with('success', "{$changed} item tersimpan.");
    }

    /** "Terapkan ke bagian ini" — one Kepala Tim for several items. */
    public function assignTeamLead(Request $request, Project $project, WorkItemService $service): RedirectResponse
    {
        $this->authorize('setWages', $project);
        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1', 'max:500'],
            'item_ids.*' => ['integer'],
            'team_lead_id' => ['nullable', 'integer', 'exists:users,id'],
            'team_lead_name' => ['nullable', 'string', 'max:255'],
        ], ['item_ids.required' => 'Tidak ada item yang dipilih.']);

        $count = $service->assignTeamLead($project, $data['item_ids'], $data['team_lead_id'] ?? null, $data['team_lead_name'] ?? null, $request->user());

        return back()->with('success', "Kepala Tim diterapkan ke {$count} item.");
    }

    /**
     * Sprint 29 Sub 09 — the pengecekan draft, saved automatically while the
     * Pengawas or the Admin Proyek fills it (ProjectPolicy::fillWorkCheck()
     * in the request). Quiet: the form keeps its own state.
     */
    public function saveDraft(SaveWorkCheckDraftRequest $request, Project $project, WorkItemService $service): RedirectResponse
    {
        $service->saveDraft($project, $request->validated(), $request->user());

        return back()->with('success', 'Draf pengecekan tersimpan.');
    }
}
