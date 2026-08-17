<?php

namespace App\Http\Controllers\Overtime;

use App\Http\Controllers\Controller;
use App\Http\Requests\Overtime\OvertimeDecisionRequest;
use App\Http\Requests\Overtime\StoreOvertimeRequestRequest;
use App\Models\OvertimeRequest;
use App\Models\Project;
use App\Services\OvertimeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRD §4.5/§6.6 / §7.1 "Overtime Request" row (PM & Finance both RU,
 * sequential; Field Staff CR own). See OvertimeService's docblock for the
 * approval state machine.
 */
class OvertimeController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $isFieldStaff = $user->hasRole('FIELD_STAFF') && ! $user->hasAnyRole(['CEO', 'PM', 'FINANCE', 'SUPERADMIN']);

        $overtimeRequests = OvertimeRequest::query()
            ->with(['staff:id,name', 'project:id,name'])
            ->when($isFieldStaff, fn ($query) => $query->where('staff_id', $user->id))
            ->byStatus($request->string('status')->value() ?: null)
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Overtime/Index', [
            'overtimeRequests' => $overtimeRequests,
            'filters' => $request->only(['status']),
            'projects' => $isFieldStaff ? Project::orderBy('name')->get(['id', 'name']) : [],
            'canSubmit' => $isFieldStaff,
            'canPmDecide' => $user->hasAnyRole(['PM', 'SUPERADMIN']),
            'canFinanceDecide' => $user->hasAnyRole(['FINANCE', 'SUPERADMIN']),
        ]);
    }

    public function store(StoreOvertimeRequestRequest $request, OvertimeService $service): RedirectResponse
    {
        $service->create($request->validated(), $request->user());

        return back()->with('success', 'Pengajuan lembur berhasil dikirim.');
    }

    public function pmApprove(OvertimeDecisionRequest $request, OvertimeRequest $overtimeRequest, OvertimeService $service): RedirectResponse
    {
        $service->pmDecision($overtimeRequest, 'approve', $request->user(), $request->validated('note'));

        return back()->with('success', 'Lembur disetujui PM.');
    }

    public function pmReject(OvertimeDecisionRequest $request, OvertimeRequest $overtimeRequest, OvertimeService $service): RedirectResponse
    {
        $service->pmDecision($overtimeRequest, 'reject', $request->user(), $request->validated('note'));

        return back()->with('success', 'Lembur ditolak PM.');
    }

    public function financeApprove(OvertimeDecisionRequest $request, OvertimeRequest $overtimeRequest, OvertimeService $service): RedirectResponse
    {
        $service->financeDecision($overtimeRequest, 'approve', $request->user(), $request->validated('note'));

        return back()->with('success', 'Lembur disetujui Finance dan dicatat sebagai pengeluaran.');
    }

    public function financeReject(OvertimeDecisionRequest $request, OvertimeRequest $overtimeRequest, OvertimeService $service): RedirectResponse
    {
        $service->financeDecision($overtimeRequest, 'reject', $request->user(), $request->validated('note'));

        return back()->with('success', 'Lembur ditolak Finance.');
    }
}
