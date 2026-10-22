<?php

namespace App\Http\Controllers\Projects;

use App\Enums\WorkItemQaStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\DecideWorkItemQaRequest;
use App\Models\Project;
use App\Models\WorkItemQaCheck;
use App\Services\WorkItemQaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sprint 29 Sub 12 (G1) — QA per item RAB on Detail Proyek › Timeline & QC ›
 * QC Item. A decision is a step (pop-up, partial reload `RELOAD.itemQa`).
 */
class WorkItemQaController extends Controller
{
    public function decide(DecideWorkItemQaRequest $request, Project $project, WorkItemQaService $service): RedirectResponse
    {
        $data = $request->validated();
        $count = $service->decide($project, $data, $request->file('photo'), $request->user());

        return back()->with('success', $data['decision'] === WorkItemQaStatus::Lolos->value
            ? "{$count} item lolos QA."
            : 'Item ditandai perlu perbaikan — temuan dicatat.');
    }

    /** The QA photo, streamed from the private disk (never a public URL). */
    public function photo(Project $project, WorkItemQaCheck $check): StreamedResponse
    {
        $this->authorize('viewItemQa', $project);
        abort_unless((int) $check->project_id === (int) $project->id && $check->photo_path, 404);

        $disk = Storage::disk(WorkItemQaCheck::DISK);
        abort_unless($disk->exists($check->photo_path), 404);

        return $disk->response($check->photo_path, "qa-item-{$check->id}.webp", [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
