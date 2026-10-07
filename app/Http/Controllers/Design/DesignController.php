<?php

namespace App\Http\Controllers\Design;

use App\Enums\DesignStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Design\AssignDesignRequest;
use App\Http\Requests\Design\RequestDesignRevisionRequest;
use App\Http\Requests\Design\StoreDesignDiscussionRequest;
use App\Http\Requests\Design\UpdateDesignRequest;
use App\Models\Design;
use App\Models\Quotation;
use App\Models\User;
use App\Services\DesignService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRD §4.2. Since Sprint 12 Sub 8 a design is no longer opened by hand
 * from a lead: it is born from an approved RAB Jasa Desain (locked until
 * Finance verifies its invoice), assigned by a Kepala Desain, and sent /
 * revised / approved by Marketing. `index()` is the "Desain" sidebar
 * entry; a plain architect only lists the designs they work on.
 */
class DesignController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $isHead = $user->hasAnyRole(['KEPALA_DESAIN', 'SUPERADMIN']);

        $designs = Design::query()
            ->with(['lead:id,client_name', 'pic:id,name', 'staff:id,name'])
            ->visibleTo($user)
            ->byStatus($request->string('status')->value() ?: null)
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Design/Index', [
            'designs' => $designs,
            'filters' => $request->only(['status']),
            // Decision #15/#16 — the Kepala Desain's queue: paid for yet? assigned yet?
            'queue' => $isHead
                ? Design::query()
                    ->with(['lead:id,client_name', 'quotation:id,total_amount,client_approved_at'])
                    ->whereIn('status', DesignStatus::lockedValues())
                    ->oldest()
                    ->get(['id', 'lead_id', 'quotation_id', 'status', 'brief_note', 'created_at'])
                : null,
            'architects' => $isHead ? $this->architects() : [],
        ]);
    }

    public function show(Request $request, Design $design, DesignService $service): Response
    {
        $this->authorize('view', $design);

        $user = $request->user();
        $design->load([
            'lead:id,client_name,assigned_to',
            'pic:id,name',
            'staff:id,name',
            'assigner:id,name',
            'quotation:id,lead_id,type,status,total_amount,version',
            'revisions.requester:id,name',
        ]);
        $flow = $design->isFlowManaged();
        $isMarketing = $user->hasAnyRole(['MARKETING', 'SUPERADMIN']);

        return Inertia::render('Design/Show', [
            'design' => $design,
            'canManage' => ! $design->status->isLocked() && $user->can('update', $design),
            // Pre-Sprint-12 designs keep the old Client ACC button (Marketing / Designer).
            'canClientAcc' => ! $flow && $user->hasAnyRole(['MARKETING', 'DESIGNER', 'SUPERADMIN']),
            'canAssign' => $flow && ! $design->client_acc && $user->hasAnyRole(['KEPALA_DESAIN', 'SUPERADMIN'])
                && $design->status !== DesignStatus::MenungguBayar,
            'canMarketingActions' => $flow && $isMarketing,
            'discussion' => $service->threadFor($design, $user),
            // Sprint 17 Sub 04 — the lead's running RAB Proyek ("already requested automatically").
            'projectRab' => Quotation::runningProjectRabSummary($design->lead_id),
            // `is_active` lets the sub-staff picker offer active designers
            // only (UpdateDesignRequest's rule) while still naming a
            // deactivated one already on the team.
            'designers' => User::role('DESIGNER')->orderBy('name')->get(['id', 'name', 'is_active']),
        ]);
    }

    public function update(UpdateDesignRequest $request, Design $design, DesignService $service): RedirectResponse
    {
        $service->update($design, $request->validated());

        return back()->with('success', 'Brief desain berhasil diperbarui.');
    }

    /** Decision #15 — Kepala Desain only (route `role:KEPALA_DESAIN`). */
    public function assign(AssignDesignRequest $request, Design $design, DesignService $service): RedirectResponse
    {
        $wasWaiting = $design->status === DesignStatus::MenungguPenugasan;
        $service->assign($design, $request->validated(), $request->user());

        return back()->with('success', $wasWaiting ? 'Desain ditugaskan.' : 'Penugasan desain diperbarui.');
    }

    /** Decision #17 — Marketing only (route `role:MARKETING`). */
    public function sendToClient(Request $request, Design $design, DesignService $service): RedirectResponse
    {
        $service->sendToClient($design, $request->user());

        return back()->with('success', 'Desain ditandai terkirim ke klien.');
    }

    public function requestRevision(RequestDesignRevisionRequest $request, Design $design, DesignService $service): RedirectResponse
    {
        $design = $service->requestRevision($design, $request->validated('note'), $request->user());

        return back()->with('success', "Revisi #{$design->revision_count} dikirim ke arsitek.");
    }

    public function markClientApproved(Request $request, Design $design, DesignService $service): RedirectResponse
    {
        // Sprint 17 Sub 04 — says whether the RAB Proyek was requested now or was already running.
        $approval = $service->markClientApproved($design, $request->user());

        return back()->with('success', $approval->message());
    }

    /** Pre-Sprint-12 designs: Client ACC opens the project quotation (PRD §4.2). */
    public function clientAcc(Request $request, Design $design, DesignService $service): RedirectResponse
    {
        // Sprint 12 designs go through the new approval (same as DesignService::clientAcc()).
        if ($design->isFlowManaged()) {
            return back()->with('success', $service->markClientApproved($design, $request->user())->message());
        }

        $design = $service->clientAcc($design, $request->user());

        // Quotation is a sibling of Design via lead_id, not a direct
        // relation on Design — go through the Lead (Lead::quotation()).
        $quotation = $design->lead->quotation;

        return redirect()->route('quotations.show', $quotation)
            ->with('success', 'Desain di-ACC klien. Quotation baru telah dibuka.');
    }

    /** D6 — the Arsitek ↔ Estimator thread (route `role:DESIGNER|ESTIMATOR` + DesignPolicy::discuss()). */
    public function discuss(StoreDesignDiscussionRequest $request, Design $design, DesignService $service): RedirectResponse
    {
        $this->authorize('discuss', $design);
        $service->discuss($design, $request->validated(), $request->user());

        return back()->with('success', 'Pesan terkirim.');
    }

    /** @return Collection<int, User> */
    private function architects()
    {
        return User::role('DESIGNER')->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }
}
