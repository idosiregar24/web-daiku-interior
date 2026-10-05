<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\AllocateBudgetItemsRequest;
use App\Http\Requests\Projects\DecideOverrunRequest;
use App\Http\Requests\Projects\RecordRealizationRequest;
use App\Http\Requests\Projects\ReorderBudgetPostsRequest;
use App\Http\Requests\Projects\ReverseRealizationRequest;
use App\Http\Requests\Projects\SaveBudgetPostRequest;
use App\Models\BudgetLine;
use App\Models\BudgetOverrunRequest;
use App\Models\BudgetPost;
use App\Models\BudgetRealization;
use App\Models\Project;
use App\Services\BudgetRealizationService;
use App\Services\ProjectBudgetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sprint 12 decisions #23–#26 — "Alokasi Dana Proyek" writes, by the PM
 * of the project only (ProjectPolicy::manageBudget(), in each Form
 * Request). The tab's data comes with ProjectController::show().
 */
class ProjectBudgetController extends Controller
{
    public function storePost(SaveBudgetPostRequest $request, Project $project, ProjectBudgetService $service): RedirectResponse
    {
        $post = $service->createPost($project, $request->validated('name'), $request->user());

        return back()->with('success', "Pos \"{$post->name}\" dibuat.");
    }

    public function updatePost(SaveBudgetPostRequest $request, Project $project, BudgetPost $post, ProjectBudgetService $service): RedirectResponse
    {
        $this->ensurePostOf($project, $post);
        $service->renamePost($post, $request->validated('name'), $request->user());

        return back()->with('success', 'Nama pos diperbarui.');
    }

    public function reorderPosts(ReorderBudgetPostsRequest $request, Project $project, ProjectBudgetService $service): RedirectResponse
    {
        $service->reorderPosts($project, $request->validated('post_ids'), $request->user());

        return back()->with('success', 'Urutan pos diperbarui.');
    }

    public function destroyPost(Request $request, Project $project, BudgetPost $post, ProjectBudgetService $service): RedirectResponse
    {
        $this->authorize('manageBudget', $project);
        $this->ensurePostOf($project, $post);
        $service->deletePost($post, $request->user());

        return back()->with('success', 'Pos dihapus.');
    }

    /** Into a post (moving it out of another), or — without a post — back to "belum dialokasikan". */
    public function allocate(AllocateBudgetItemsRequest $request, Project $project, ProjectBudgetService $service): RedirectResponse
    {
        $itemIds = $request->validated('item_ids');

        if ($postId = $request->validated('budget_post_id')) {
            $post = $project->budgetPosts()->findOrFail($postId);
            $service->allocate($post, $itemIds, $request->user());

            return back()->with('success', "Item dimasukkan ke pos \"{$post->name}\".");
        }

        $service->unallocate($project, $itemIds, $request->user());

        return back()->with('success', 'Item dikembalikan ke daftar belum dialokasikan.');
    }

    /** Sprint 12 #27 — an item's real cost; over the post budget it is refused (#28). */
    public function recordRealization(RecordRealizationRequest $request, Project $project, BudgetLine $line, BudgetRealizationService $service): RedirectResponse
    {
        $this->ensureLineOf($project, $line);
        $service->record($line, $request->validated(), $request->user());

        return back()->with('success', "Realisasi \"{$line->description}\" dicatat.");
    }

    /** Append-only correction of a realisation. */
    public function reverseRealization(ReverseRealizationRequest $request, Project $project, BudgetRealization $realization, BudgetRealizationService $service): RedirectResponse
    {
        $this->ensureLineOf($project, $realization->line);
        $service->reverse($realization, $request->validated('note'), $request->user());

        return back()->with('success', 'Realisasi dibatalkan dengan baris koreksi.');
    }

    /** Decision #28 — "Ajukan ke CEO" for the realisation that was refused. */
    public function requestOverrun(RecordRealizationRequest $request, Project $project, BudgetLine $line, BudgetRealizationService $service): RedirectResponse
    {
        $this->ensureLineOf($project, $line);
        $service->requestOverrun($line, $request->validated(), $request->user());

        return back()->with('success', 'Pengajuan overrun dikirim ke CEO.');
    }

    /** Decision #28 — CEO only (route `role:CEO`). */
    public function decideOverrun(DecideOverrunRequest $request, BudgetOverrunRequest $overrun, BudgetRealizationService $service): RedirectResponse
    {
        $approve = $request->validated('decision') === 'approve';
        $service->decide($overrun, $approve, $request->validated('note'), $request->user());

        return back()->with('success', $approve ? 'Overrun disetujui — realisasi tercatat.' : 'Overrun ditolak.');
    }

    private function ensureLineOf(Project $project, BudgetLine $line): void
    {
        abort_unless((int) $line->post?->project_id === (int) $project->id, 404);
    }

    private function ensurePostOf(Project $project, BudgetPost $post): void
    {
        abort_unless((int) $post->project_id === (int) $project->id, 404);
    }
}
