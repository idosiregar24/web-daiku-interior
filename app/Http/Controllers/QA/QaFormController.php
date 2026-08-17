<?php

namespace App\Http\Controllers\QA;

use App\Http\Controllers\Controller;
use App\Http\Requests\QA\ReviewQaFormRequest;
use App\Models\QaForm;
use App\Services\QaFormService;
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
        $qaForms = QaForm::query()
            ->with(['project:id,name', 'milestone:id,name,order', 'reviewer:id,name'])
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
        $qaForm->load(['project:id,name', 'milestone:id,name,order,status', 'reviewer:id,name']);

        return Inertia::render('QA/Show', [
            'qaForm' => $qaForm,
            'canReview' => request()->user()->hasAnyRole(['QA', 'SUPERADMIN']),
        ]);
    }

    public function update(ReviewQaFormRequest $request, QaForm $qaForm, QaFormService $service): RedirectResponse
    {
        $service->review(
            $qaForm,
            $request->validated('decision'),
            $request->validated('checklist_data'),
            $request->validated('notes'),
            $request->user(),
        );

        return back()->with('success', 'Keputusan QA berhasil disimpan.');
    }
}
