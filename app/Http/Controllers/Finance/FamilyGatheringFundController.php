<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\RecordFundExpenseRequest;
use App\Models\BankAccount;
use App\Models\FamilyGatheringFund;
use App\Services\FamilyGatheringFundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "FamilyGatheringFund page Finance: total dana + riwayat"
 * (.claude/plan/sprint-03.md Week 6) — PRD §7.1 "Finance – Family Fund"
 * row (CEO read, Finance CRUD). Sprint 9 decision #10: only penalties
 * already paid can be spent (FamilyGatheringFundService::summary()).
 */
class FamilyGatheringFundController extends Controller
{
    public function index(Request $request, FamilyGatheringFundService $service): Response
    {
        $type = in_array($request->query('type'), ['INCOME', 'EXPENSE'], true) ? $request->query('type') : null;
        $canRecordExpense = $request->user()->hasAnyRole(['FINANCE', 'SUPERADMIN']);

        $entries = FamilyGatheringFund::query()
            ->byType($type)
            ->with([
                'recorder:id,name',
                'sourcePenalty:id,staff_id,is_deducted,collected_at',
                'sourcePenalty.staff:id,name',
                'financeTransaction:id,bank_account_id,date',
                'financeTransaction.bankAccount:id,label',
            ])
            ->latest('created_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('FamilyFund/Index', [
            'entries' => $entries,
            'filters' => array_filter(['type' => $type]),
            'summary' => $service->summary(),
            'canRecordExpense' => $canRecordExpense,
            'bankAccounts' => $canRecordExpense
                ? BankAccount::where('is_active', true)->orderBy('label')->get(['id', 'label'])
                : [],
        ]);
    }

    public function recordExpense(RecordFundExpenseRequest $request, FamilyGatheringFundService $service): RedirectResponse
    {
        $service->recordExpense($request->validated(), $request->user());

        return back()->with('success', 'Penggunaan dana berhasil dicatat.');
    }
}
