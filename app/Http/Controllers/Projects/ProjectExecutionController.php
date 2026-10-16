<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\DecideExecutionRequest;
use App\Http\Requests\Projects\RescheduleTargetRequest;
use App\Http\Requests\Projects\SaveTechnicalMeetingRequest;
use App\Http\Requests\Projects\StoreRepairTaskRequest;
use App\Http\Requests\Projects\StoreSiteFindingRequest;
use App\Http\Requests\Projects\SubmitWeeklyReportRequest;
use App\Models\Project;
use App\Models\SiteFinding;
use App\Models\SupervisorPayment;
use App\Models\WeeklyReport;
use App\Services\ProductionService;
use App\Services\SiteFindingService;
use App\Services\SupervisorPaymentService;
use App\Services\TaskService;
use App\Services\TechnicalMeetingService;
use App\Services\WeeklyReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sprint 24 — the project's execution flow after "Buka Proyek" (the
 * Gambar Kerja part lives in Design\WorkingDrawingController). Thin: who
 * may act is ProjectPolicy (runMeeting / supervise / approveExecution /
 * manageTargets) on top of each route's `role:`; the rules are the services'.
 * Every action returns to the project's Eksekusi tab.
 */
class ProjectExecutionController extends Controller
{
    // ── D3 Technical Meeting (PM / Asisten PM) ─────────────────────────────

    public function saveMeeting(SaveTechnicalMeetingRequest $request, Project $project, TechnicalMeetingService $service): RedirectResponse
    {
        $service->save($project, $request->validated(), $request->user());

        return back()->with('success', 'Technical Meeting disimpan.');
    }

    public function completeMeeting(Request $request, Project $project, TechnicalMeetingService $service): RedirectResponse
    {
        $this->authorize('runMeeting', $project);
        $service->complete($project, $request->user());

        return back()->with('success', 'TM selesai — Pengawas diminta melengkapi syarat produksi.');
    }

    // ── D4/D5/E3 Jasa Pengawas ─────────────────────────────────────────────

    public function requestDp(Request $request, Project $project, SupervisorPaymentService $service): RedirectResponse
    {
        $this->authorize('supervise', $project);
        $service->requestDp($project, $request->user());

        return back()->with('success', 'DP pengawas diajukan ke PM.');
    }

    public function requestSettlement(Request $request, Project $project, SupervisorPaymentService $service): RedirectResponse
    {
        $this->authorize('supervise', $project);
        $service->requestSettlement($project, $request->user());

        return back()->with('success', 'Pelunasan jasa pengawas diajukan ke PM.');
    }

    public function decidePayment(DecideExecutionRequest $request, SupervisorPayment $supervisorPayment, SupervisorPaymentService $service): RedirectResponse
    {
        $this->authorize('approveExecution', $supervisorPayment->project);
        $approve = $request->validated('decision') === 'approve';
        $amount = $request->filled('amount') ? (float) $request->validated('amount') : null;

        $service->decide($supervisorPayment, $approve, $amount, $request->validated('note'), $request->user());

        return back()->with('success', $approve ? 'Pengajuan disetujui — diteruskan ke Finance.' : 'Pengajuan ditolak.');
    }

    // ── D10 Laporan mingguan ───────────────────────────────────────────────

    public function submitWeeklyReport(SubmitWeeklyReportRequest $request, Project $project, WeeklyReportService $service): RedirectResponse
    {
        $service->submit($project, $request->validated(), $request->user());

        return back()->with('success', 'Laporan mingguan dikirim ke PM.');
    }

    public function decideWeeklyReport(DecideExecutionRequest $request, WeeklyReport $weeklyReport, WeeklyReportService $service): RedirectResponse
    {
        $this->authorize('approveExecution', $weeklyReport->project);
        $approve = $request->validated('decision') === 'approve';
        $amount = $request->filled('amount') ? (float) $request->validated('amount') : null;

        $service->decide($weeklyReport, $approve, $amount, $request->validated('note'), $request->user());

        return back()->with('success', $approve ? 'Laporan mingguan disetujui.' : 'Laporan mingguan ditolak.');
    }

    // ── D11 Berangkat ke lokasi ────────────────────────────────────────────

    public function approveDeparture(Request $request, Project $project, ProductionService $service): RedirectResponse
    {
        $this->authorize('approveExecution', $project);
        $service->approveDeparture($project, $request->user());

        return back()->with('success', 'Berangkat ke lokasi disetujui — tahap Pemasangan dimulai.');
    }

    // ── D12 Temuan finishing ───────────────────────────────────────────────

    public function storeFinding(StoreSiteFindingRequest $request, Project $project, SiteFindingService $service): RedirectResponse
    {
        $service->record($project, $request->validated(), $request->user());

        return back()->with('success', 'Temuan dicatat.');
    }

    public function repairTask(StoreRepairTaskRequest $request, SiteFinding $siteFinding, SiteFindingService $service): RedirectResponse
    {
        $this->authorize('manageTargets', $siteFinding->project);
        $service->makeRepairTask($siteFinding, $request->validated(), $request->user());

        return back()->with('success', 'Task perbaikan dibuat.');
    }

    public function resolveFinding(Request $request, SiteFinding $siteFinding, SiteFindingService $service): RedirectResponse
    {
        $project = $siteFinding->project;
        abort_unless($request->user()->can('supervise', $project) || $request->user()->can('approveExecution', $project), 403);
        $service->resolve($siteFinding, $request->user());

        return back()->with('success', 'Temuan ditandai selesai.');
    }

    // ── D9 Tukar hari ──────────────────────────────────────────────────────

    public function swapDays(RescheduleTargetRequest $request, Project $project, TaskService $service): RedirectResponse
    {
        $this->authorize('manageTargets', $project);
        $count = $service->swapDays(
            $project,
            $request->validated('from_date'),
            $request->validated('to_date'),
            $request->filled('assignee_id') ? (int) $request->validated('assignee_id') : null,
            $request->user(),
        );

        return back()->with('success', "{$count} target ditukar harinya.");
    }
}
