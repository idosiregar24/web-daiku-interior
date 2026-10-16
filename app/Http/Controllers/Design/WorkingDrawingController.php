<?php

namespace App\Http\Controllers\Design;

use App\Enums\WorkingDrawingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Design\AssignDesignRequest;
use App\Http\Requests\Design\UpdateWorkingDrawingLinksRequest;
use App\Models\User;
use App\Models\WorkingDrawing;
use App\Services\WorkingDrawingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 24 D2 — "Gambar Kerja": a tab of the Desain hub (list) and its
 * page (assign · links · "Gambar Kerja Selesai"). The assignment form is
 * the design's (AssignDesignRequest — same fields, active architects).
 */
class WorkingDrawingController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $status = $request->query('status');

        $drawings = WorkingDrawing::query()
            ->visibleTo($user)
            ->when($user->hasRole('ASISTEN_PM') && ! $user->hasAnyRole(['CEO', 'PM', 'SUPERADMIN']), fn ($query) => $query->whereHas('project', fn ($project) => $project->assistedBy($user)))
            ->when(in_array($status, array_column(WorkingDrawingStatus::cases(), 'value'), true), fn ($query) => $query->where('status', $status))
            ->with(['project:id,name,lead_id,phase', 'project.lead:id,client_name', 'pic:id,name'])
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Design/WorkingDrawings', [
            'drawings' => $drawings,
            'filters' => ['status' => $status],
        ]);
    }

    public function show(Request $request, WorkingDrawing $workingDrawing): Response
    {
        $this->authorize('view', $workingDrawing);
        $user = $request->user();
        $workingDrawing->load([
            'project:id,name,lead_id,pm_id,supervisor_id,phase,start_date,end_date',
            'project.lead:id,client_name',
            'project.pm:id,name',
            'project.supervisor:id,name',
            'pic:id,name',
            'staff:id,name',
            'assigner:id,name',
            'completer:id,name',
            'linksUpdater:id,name',
        ]);
        $canAssign = $user->hasAnyRole(['KEPALA_DESAIN', 'SUPERADMIN']) && $workingDrawing->status !== WorkingDrawingStatus::Selesai;

        return Inertia::render('Design/WorkingDrawingShow', [
            'drawing' => $workingDrawing,
            'canAssign' => $canAssign,
            'canWork' => $user->can('work', $workingDrawing) && $user->hasAnyRole(['DESIGNER', 'SUPERADMIN']),
            'architects' => $canAssign ? User::role('DESIGNER')->where('is_active', true)->orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    public function assign(AssignDesignRequest $request, WorkingDrawing $workingDrawing, WorkingDrawingService $service): RedirectResponse
    {
        $service->assign($workingDrawing, $request->validated(), $request->user());

        return back()->with('success', 'Gambar kerja ditugaskan.');
    }

    public function updateLinks(UpdateWorkingDrawingLinksRequest $request, WorkingDrawing $workingDrawing, WorkingDrawingService $service): RedirectResponse
    {
        $changed = $service->updateLinks($workingDrawing, $request->validated(), $request->user());

        return back()->with('success', $changed ? 'Link gambar kerja disimpan.' : 'Tidak ada perubahan.');
    }

    public function complete(Request $request, WorkingDrawing $workingDrawing, WorkingDrawingService $service): RedirectResponse
    {
        $this->authorize('work', $workingDrawing);
        $service->complete($workingDrawing, $request->user());

        return back()->with('success', 'Gambar kerja selesai — proyek lanjut ke Technical Meeting.');
    }
}
