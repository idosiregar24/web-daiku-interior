<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Penalty;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRD §7.1 "Penalty – View" row — CEO/PM/Finance read all, Field Staff
 * read only their own (`R*`). Read-only everywhere: penalties are only
 * ever written by PenaltyService's automated job.
 */
class PenaltyController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $isFieldStaff = $user->hasRole('FIELD_STAFF') && ! $user->hasAnyRole(['CEO', 'PM', 'FINANCE', 'SUPERADMIN']);

        $scoped = fn () => Penalty::query()
            ->when($isFieldStaff, fn ($query) => $query->where('staff_id', $user->id))
            ->forStaff($isFieldStaff ? null : ($request->integer('staff_id') ?: null));

        $penalties = $scoped()
            ->with('staff:id,name')
            ->latest('date_occurred')
            ->paginate(20)
            ->withQueryString();

        // CSV Sprint 6 "Penalty list PM: per tukang + total" — aggregated
        // over the same scope as the list (a tukang only sees their own).
        $perStaff = $scoped()
            ->selectRaw('staff_id, COUNT(*) as penalty_count, SUM(amount) as total_amount, MAX(date_occurred) as last_date')
            ->groupBy('staff_id')
            ->orderByDesc('total_amount')
            ->with('staff:id,name')
            ->get()
            ->map(fn (Penalty $row) => [
                'staffId' => $row->staff_id,
                'name' => $row->staff?->name ?? '—',
                'count' => (int) $row->penalty_count,
                'total' => (float) $row->total_amount,
                'lastDate' => $row->getRawOriginal('last_date'),
            ]);

        return Inertia::render('Penalty/Index', [
            'penalties' => $penalties,
            'filters' => $request->only(['staff_id']),
            'fieldStaff' => $isFieldStaff ? [] : User::role('FIELD_STAFF')->orderBy('name')->get(['id', 'name']),
            'perStaff' => $perStaff,
            'grandTotal' => (float) $perStaff->sum('total'),
            // PRD §7.1 "Finance – Family Fund": CEO/Finance only — PM sees
            // the summary but gets no link into the fund ledger.
            'canViewFamilyFund' => $user->hasAnyRole(['CEO', 'FINANCE', 'SUPERADMIN']),
        ]);
    }
}
