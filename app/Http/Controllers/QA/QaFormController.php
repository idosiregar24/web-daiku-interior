<?php

namespace App\Http\Controllers\QA;

use App\Http\Controllers\Controller;
use App\Http\Requests\QA\ReviewQaFormRequest;
use App\Models\QaForm;
use App\Services\QaFormService;
use App\Services\SiteFindingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRD §7.1 "QA Form" row — CEO/PM read, QA CRUD (review only; QaForm rows
 * themselves are only ever created by MilestoneService::markDone(), see
 * QaFormService's docblock).
 */
class QaFormController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $qaForms = QaForm::query()
            ->with(['project:id,name', 'milestone:id,name,order', 'reviewer:id,name'])
            // Sprint 24 D14 — a Pengawas (QA/QC) works on its own projects (plus those without one).
            ->when(
                $user->hasRole('QA') && ! $user->hasAnyRole(['CEO', 'PM', 'SUPERADMIN']),
                fn ($query) => $query->whereHas('project', fn ($project) => $project->qaReviewableBy($user)),
            )
            ->when(
                $request->string('status')->value(),
                fn ($query, $status) => $query->where('status', $status),
            )
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('QA/Index', [
            'qaForms' => $qaForms,
            'filters' => $request->only(['status']),
        ]);
    }

    public function show(QaForm $qaForm): Response
    {
        $qaForm->load(['project:id,name,supervisor_id', 'milestone:id,project_id,name,order,status', 'reviewer:id,name']);

        return Inertia::render('QA/Show', [
            'qaForm' => $qaForm,
            // Sprint 24 D14 — the project's Pengawas (QA/QC) (QaFormPolicy).
            'canReview' => request()->user()->can('review', $qaForm),
            // Sprint 28 K10 — the same count QaFormService::review() refuses an approval on (a number only, no task data).
            'openFindingsCount' => $qaForm->milestone ? SiteFindingService::openFor($qaForm->milestone) : 0,
        ]);
    }

    public function update(ReviewQaFormRequest $request, QaForm $qaForm, QaFormService $service): RedirectResponse
    {
        $this->authorize('review', $qaForm);

        $service->review(
            $qaForm,
            $request->validated('decision'),
            $request->validated('checklist_data'),
            $request->validated('notes'),
            $request->user(),
        );

        return back()->with('success', 'Keputusan Pengawas (QA/QC) disimpan.');
    }
}
