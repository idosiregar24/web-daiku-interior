<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\RecordPenaltyPaymentRequest;
use App\Models\BankAccount;
use App\Models\Penalty;
use App\Models\User;
use App\Services\PenaltyCollectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRD §7.1 "Penalty – View" row — CEO/PM/Finance read all, Field Staff
 * read only their own (`R*`). Penalties themselves are only ever created
 * by PenaltyService's automated job; the one write here is Finance
 * recording that a tukang paid them (Sprint 9 decision #10 — manual
 * payment, wages not deducted), which is a finance transaction and so
 * Finance-only (route middleware).
 */
class PenaltyController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $isFieldStaff = $user->hasRole('FIELD_STAFF') && ! $user->hasAnyRole(['CEO', 'PM', 'FINANCE', 'SUPERADMIN']);
        $canRecordPayment = $user->hasAnyRole(['FINANCE', 'SUPERADMIN']);

        $staffId = $isFieldStaff ? null : ($request->integer('staff_id') ?: null);
        $status = in_array($request->query('status'), [Penalty::STATUS_UNPAID, Penalty::STATUS_PAID], true)
            ? $request->query('status')
            : null;

        // Everything this viewer may see — a tukang only their own.
        $visible = fn () => Penalty::query()
            ->when($isFieldStaff, fn ($query) => $query->where('staff_id', $user->id));

        $penalties = $visible()
            ->forStaff($staffId)
            ->byPaymentStatus($status)
            ->with(['staff:id,name', 'collector:id,name'])
            // Which account received it is finance data (PRD §7.1 "Finance –
            // Transaction": no Field Staff access) — the tukang sees only
            // that and when it was paid.
            ->when(! $isFieldStaff, fn ($query) => $query->with([
                'financeTransaction:id,bank_account_id,date',
                'financeTransaction.bankAccount:id,label',
            ]))
            ->latest('date_occurred')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        // Totals follow the tukang filter but not the status filter (they
        // are the paid/unpaid split themselves).
        $totals = $visible()
            ->forStaff($staffId)
            ->toBase()
            ->selectRaw('COUNT(*) as penalty_count')
            ->selectRaw('COALESCE(SUM(amount), 0) as total_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_deducted = 0 THEN amount ELSE 0 END), 0) as unpaid_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_deducted = 0 THEN 1 ELSE 0 END), 0) as unpaid_count')
            ->first();

        // CSV Sprint 6 "Penalty list PM: per tukang + total" — over every
        // tukang the viewer may see (not narrowed by the filters, so it
        // stays usable as the filter), most outstanding first.
        $perStaff = $visible()
            ->selectRaw('staff_id, COUNT(*) as penalty_count, SUM(amount) as total_amount, MAX(date_occurred) as last_date')
            ->selectRaw('SUM(CASE WHEN is_deducted = 0 THEN 1 ELSE 0 END) as unpaid_count')
            ->selectRaw('SUM(CASE WHEN is_deducted = 0 THEN amount ELSE 0 END) as unpaid_amount')
            ->groupBy('staff_id')
            ->orderByDesc('unpaid_amount')
            ->orderByDesc('total_amount')
            ->with('staff:id,name')
            ->get()
            ->map(fn (Penalty $row) => [
                'staffId' => $row->staff_id,
                'name' => $row->staff?->name ?? '—',
                'count' => (int) $row->penalty_count,
                'total' => (float) $row->total_amount,
                'unpaidCount' => (int) $row->unpaid_count,
                'outstanding' => (float) $row->unpaid_amount,
                'lastDate' => $row->getRawOriginal('last_date'),
            ]);

        $grandTotal = (float) $totals->total_amount;
        $outstandingTotal = (float) $totals->unpaid_amount;

        return Inertia::render('Penalty/Index', [
            'penalties' => $penalties,
            'filters' => array_filter(['staff_id' => $staffId ? (string) $staffId : null, 'status' => $status]),
            'fieldStaff' => $isFieldStaff ? [] : User::role('FIELD_STAFF')->orderBy('name')->get(['id', 'name']),
            'perStaff' => $perStaff,
            'penaltyCount' => (int) $totals->penalty_count,
            'unpaidCount' => (int) $totals->unpaid_count,
            'grandTotal' => $grandTotal,
            'paidTotal' => round($grandTotal - $outstandingTotal, 2),
            'outstandingTotal' => $outstandingTotal,
            // PRD §7.1 "Finance – Family Fund": CEO/Finance only — PM sees
            // the summary but gets no link into the fund ledger.
            'canViewFamilyFund' => $user->hasAnyRole(['CEO', 'FINANCE', 'SUPERADMIN']),
            'canRecordPayment' => $canRecordPayment,
            // The "Catat Pembayaran" dialog lists a tukang's unpaid penalties
            // from here — every unpaid one, not just the current page.
            'unpaidPenalties' => $canRecordPayment
                ? Penalty::query()->unpaid()->orderBy('date_occurred')->orderBy('id')->get(['id', 'staff_id', 'type', 'amount', 'date_occurred'])
                : [],
            'bankAccounts' => $canRecordPayment
                ? BankAccount::where('is_active', true)->orderBy('label')->get(['id', 'label'])
                : [],
        ]);
    }

    public function recordPayment(RecordPenaltyPaymentRequest $request, PenaltyCollectionService $service): RedirectResponse
    {
        $staff = User::findOrFail($request->integer('staff_id'));

        $service->recordPayment($staff, $request->validated(), $request->user());

        return back()->with('success', "Pembayaran penalti {$staff->name} berhasil dicatat.");
    }
}
