<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\RecordFundExpenseRequest;
use App\Models\FamilyGatheringFund;
use App\Services\FamilyGatheringFundService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "FamilyGatheringFund page Finance: total dana + riwayat"
 * (.claude/plan/sprint-03.md Week 6) — PRD §7.1 "Finance – Family Fund"
 * row (CEO read, Finance CRUD).
 */
class FamilyGatheringFundController extends Controller
{
    public function index(): Response
    {
        $entries = FamilyGatheringFund::query()
            ->with(['recorder:id,name', 'sourcePenalty.staff:id,name'])
            ->latest('created_at')
            ->paginate(20);

        $totalIncome = (float) FamilyGatheringFund::where('type', 'INCOME')->sum('amount');
        $totalExpense = (float) FamilyGatheringFund::where('type', 'EXPENSE')->sum('amount');

        return Inertia::render('FamilyFund/Index', [
            'entries' => $entries,
            'balance' => $totalIncome - $totalExpense,
            'totalIncome' => $totalIncome,
            'totalExpense' => $totalExpense,
        ]);
    }

    public function recordExpense(RecordFundExpenseRequest $request, FamilyGatheringFundService $service): RedirectResponse
    {
        $service->recordExpense($request->validated(), $request->user());

        return back()->with('success', 'Penggunaan dana berhasil dicatat.');
    }
}
