<?php

namespace App\Http\Controllers;

use App\Models\Penalty;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 13 H1/H10 — "Lainnya", the fourth button of the Tukang's bottom
 * navigation on a phone: this month's penalty total in plain sight (the
 * same own-rows rule as the Penalti page), then the less frequent pages.
 */
class MoreController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $month = now('Asia/Jakarta');

        $totals = Penalty::query()
            ->where('staff_id', $user->id)
            ->inMonth($month)
            ->toBase()
            ->selectRaw('COUNT(*) as penalty_count')
            ->selectRaw('COALESCE(SUM(amount), 0) as total_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_deducted = 0 THEN amount ELSE 0 END), 0) as unpaid_amount')
            ->first();

        return Inertia::render('More/Index', [
            'penaltyThisMonth' => [
                'month' => $month->translatedFormat('F Y'),
                'count' => (int) $totals->penalty_count,
                'total' => (float) $totals->total_amount,
                'unpaid' => (float) $totals->unpaid_amount,
            ],
        ]);
    }
}
