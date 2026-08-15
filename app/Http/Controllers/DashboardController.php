<?php

namespace App\Http\Controllers;

use App\Enums\LeadStatus;
use App\Models\Lead;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * PRD §4.1 follow-up reminder (.claude/plan/sprint-02.md Week 3, Ido
     * task 3): leads whose follow-up date is overdue or due within 3 days,
     * scoped to the Marketing user's own leads (CEO/SUPERADMIN see all).
     * A dedicated broadcast/Echo notification is deferred — no other
     * PRD §4.9 notification infra exists yet this sprint, so this widget
     * is the "tampil di dashboard Marketing" half of the task; the
     * cross-cutting notification system lands with whichever module
     * needs it first.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $canSeeFollowUps = $user->hasAnyRole(['CEO', 'MARKETING', 'SUPERADMIN']);

        $followUps = $canSeeFollowUps
            ? Lead::query()
                ->with('assignee:id,name')
                ->when(
                    $user->hasRole('MARKETING') && ! $user->hasAnyRole(['CEO', 'SUPERADMIN']),
                    fn ($query) => $query->where('assigned_to', $user->id),
                )
                ->whereNotNull('follow_up_date')
                ->whereNotIn('status', [LeadStatus::Lost->value, LeadStatus::Closing->value])
                ->where('follow_up_date', '<=', now()->addDays(3)->toDateString())
                ->orderBy('follow_up_date')
                ->limit(10)
                ->get(['id', 'client_name', 'contact', 'status', 'follow_up_date', 'assigned_to'])
            : collect();

        return Inertia::render('Dashboard', [
            'followUps' => $followUps,
        ]);
    }
}
