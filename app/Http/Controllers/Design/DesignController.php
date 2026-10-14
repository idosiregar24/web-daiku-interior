<?php

namespace App\Http\Controllers\Design;

use App\Enums\DesignStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Design\AssignDesignRequest;
use App\Http\Requests\Design\StoreDesignDiscussionRequest;
use App\Http\Requests\Design\UpdateDesignLinksRequest;
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
 * Finance verifies its invoice) and assigned by a Kepala Desain. Since
 * Sprint 23 (G) everything with the client runs in a WhatsApp group: the
 * page keeps the group link + design links ("Link Desain") and one action,
 * "Desain Disetujui Klien". `index()` is the "Desain" sidebar entry; a
 * plain architect only lists the designs they work on.
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
            'clientApprover:id,name',
            'linksUpdater:id,name',
        ]);
        $flow = $design->isFlowManaged();

        return Inertia::render('Design/Show', [
            'design' => $design,
            // Pre-Sprint-12 designs: the brief form (team, status, links).
            'canManage' => ! $flow && ! $design->status->isLocked() && $user->can('update', $design),
            // Pre-Sprint-12 designs keep the old Client ACC button (Marketing / Designer).
            'canClientAcc' => ! $flow && $user->hasAnyRole(['MARKETING', 'DESIGNER', 'SUPERADMIN']),
            'canAssign' => $flow && ! $design->client_acc && $user->hasAnyRole(['KEPALA_DESAIN', 'SUPERADMIN'])
                && $design->status !== DesignStatus::MenungguBayar,
            // Sprint 23 K18/K19b — the WhatsApp group & design links: the lead's Marketing or the design team.
            'canEditLinks' => $flow && ! $design->status->isLocked() && $user->can('manageLinks', $design),
            // Sprint 23 K19 — "Desain Disetujui Klien": the same people (the server also wants a saved link).
            'canApprove' => $design->awaitsClientApproval() && $user->can('approveForClient', $design),
            'discussion' => $service->threadFor($design, $user),
            // Sprint 17 Sub 04 — the lead's running RAB Proyek ("already requested automatically").
            'projectRab' => Quotation::runningProjectRabSummary($design->lead_id),
            // `is_active` lets the sub-staff picker offer active designers
            // only (UpdateDesignRequest's rule) while still naming a
            // deactivated one already on the team.
            'designers' => User::role('DESIGNER')->orderBy('name')->get(['id', 'name', 'is_active']),
        ]);
    }

    /** Pre-Sprint-12 designs only — a Sprint 12 design saves its links through updateLinks(). */
    public function update(UpdateDesignRequest $request, Design $design, DesignService $service): RedirectResponse
    {
        $service->update($design, $request->validated());

        return back()->with('success', 'Brief desain berhasil diperbarui.');
    }

    /** Sprint 23 K18/K19b — route `role:MARKETING|DESIGNER` + DesignPolicy::manageLinks() (in the request). */
    public function updateLinks(UpdateDesignLinksRequest $request, Design $design, DesignService $service): RedirectResponse
    {
        $changed = $service->updateLinks($design, $request->validated(), $request->user());

        return back()->with('success', $changed ? 'Link grup WhatsApp & link desain disimpan.' : 'Tidak ada perubahan link.');
    }

    /** Decision #15 — Kepala Desain only (route `role:KEPALA_DESAIN`). */
    public function assign(AssignDesignRequest $request, Design $design, DesignService $service): RedirectResponse
    {
        $wasWaiting = $design->status === DesignStatus::MenungguPenugasan;
        $service->assign($design, $request->validated(), $request->user());

        return back()->with('success', $wasWaiting ? 'Desain ditugaskan.' : 'Penugasan desain diperbarui.');
    }

    /** Sprint 23 K19 — route `role:MARKETING|DESIGNER` + DesignPolicy::approveForClient(). */
    public function markClientApproved(Request $request, Design $design, DesignService $service): RedirectResponse
    {
        $this->authorize('approveForClient', $design);

        // Sprint 17 Sub 04 — says whether the RAB Proyek was requested now or was already running.
        $approval = $service->markClientApproved($design, $request->user());

        return back()->with('success', $approval->message());
    }

    /** Pre-Sprint-12 designs: Client ACC opens the project quotation (PRD §4.2). */
    public function clientAcc(Request $request, Design $design, DesignService $service): RedirectResponse
    {
        // Sprint 12 designs go through "Desain Disetujui Klien" (same as DesignService::clientAcc()).
        if ($design->isFlowManaged()) {
            $this->authorize('approveForClient', $design);

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
