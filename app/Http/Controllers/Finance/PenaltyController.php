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

        $penalties = Penalty::query()
            ->with('staff:id,name')
            ->when($isFieldStaff, fn ($query) => $query->where('staff_id', $user->id))
            ->forStaff($isFieldStaff ? null : ($request->integer('staff_id') ?: null))
            ->latest('date_occurred')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Penalty/Index', [
            'penalties' => $penalties,
            'filters' => $request->only(['staff_id']),
            'fieldStaff' => $isFieldStaff ? [] : User::role('FIELD_STAFF')->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
