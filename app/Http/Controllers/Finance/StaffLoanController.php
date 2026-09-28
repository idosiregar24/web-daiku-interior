<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreStaffLoanPaymentRequest;
use App\Http\Requests\Finance\StoreStaffLoanRequest;
use App\Models\BankAccount;
use App\Models\StaffLoan;
use App\Models\User;
use App\Services\StaffLoanService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRD §4.7 "Pinjaman Tukang" — follows the §7.1 "Finance – Transaction"
 * row: CEO/PM read, Finance create/update. No edit/destroy — append-only
 * (PRD §9.4); corrections go through a new payment.
 */
class StaffLoanController extends Controller
{
    public function index(Request $request): Response
    {
        $filtered = fn (): Builder => StaffLoan::query()
            ->byStaff($request->integer('staff_id') ?: null)
            ->byStatus($request->string('status')->value() ?: null);

        return Inertia::render('Finance/StaffLoans/Index', [
            'loans' => $filtered()
                ->with(['staff:id,name', 'bankAccount:id,label', 'creator:id,name'])
                ->latest()
                ->latest('id')
                ->paginate(20)
                ->withQueryString(),
            'filters' => $request->only(['staff_id', 'status']),
            'summary' => [
                'totalAmount' => (float) $filtered()->sum('amount'),
                'totalRemaining' => (float) $filtered()->sum('remaining'),
                'ongoingCount' => $filtered()->outstanding()->count(),
            ],
            'staff' => $this->fieldStaff(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Finance/StaffLoans/Create', [
            'staff' => $this->fieldStaff(),
            'bankAccounts' => BankAccount::where('is_active', true)->orderBy('label')->get(['id', 'label']),
        ]);
    }

    public function store(StoreStaffLoanRequest $request, StaffLoanService $service): RedirectResponse
    {
        $loan = $service->create($request->validated(), $request->user());

        return redirect()
            ->route('finance.staffLoans.show', $loan)
            ->with('success', 'Pinjaman tukang berhasil dicatat.');
    }

    public function show(StaffLoan $staffLoan): Response
    {
        return Inertia::render('Finance/StaffLoans/Show', [
            'loan' => $staffLoan->load([
                'staff:id,name',
                'bankAccount:id,label',
                'creator:id,name',
                'payments' => fn ($query) => $query->with('creator:id,name')->latest('paid_date')->latest('id'),
            ]),
            'bankAccounts' => BankAccount::where('is_active', true)->orderBy('label')->get(['id', 'label']),
        ]);
    }

    public function storePayment(StoreStaffLoanPaymentRequest $request, StaffLoan $staffLoan, StaffLoanService $service): RedirectResponse
    {
        $service->recordPayment($staffLoan, $request->validated(), $request->user());

        return back()->with('success', 'Pembayaran pinjaman berhasil dicatat.');
    }

    private function fieldStaff(): Collection
    {
        return User::role('FIELD_STAFF')->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }
}
