<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreSupplierDebtPaymentRequest;
use App\Http\Requests\Finance\StoreSupplierDebtRequest;
use App\Models\BankAccount;
use App\Models\Project;
use App\Models\SupplierDebt;
use App\Models\Vendor;
use App\Services\SupplierDebtService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRD §4.7 "Hutang Supplier". RBAC per §7.1 "Finance – Transaction":
 * CEO/PM read, Finance create + record payments. No edit/destroy —
 * append-only (PRD §9.4).
 */
class SupplierDebtController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->value() ?: null;
        $search = $request->string('search')->trim()->value() ?: null;

        $debts = SupplierDebt::query()
            ->with(['vendor:id,name', 'project:id,name', 'creator:id,name'])
            ->byDisplayStatus($status)
            ->searchSupplier($search)
            // Unpaid first, then most urgent due date; no due date last.
            ->orderByRaw('CASE WHEN remaining > 0 THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Finance/SupplierDebts/Index', [
            'debts' => $debts,
            'filters' => $request->only(['status', 'search']),
            'summary' => [
                'totalOutstanding' => (float) SupplierDebt::query()->outstanding()->sum('remaining'),
                'outstandingCount' => SupplierDebt::query()->outstanding()->count(),
                'overdueTotal' => (float) SupplierDebt::query()->overdue()->sum('remaining'),
                'overdueCount' => SupplierDebt::query()->overdue()->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Finance/SupplierDebts/Create', [
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            'vendors' => Vendor::options(),
        ]);
    }

    public function store(StoreSupplierDebtRequest $request, SupplierDebtService $service): RedirectResponse
    {
        $debt = $service->create($request->validated(), $request->user());

        return redirect()
            ->route('finance.supplierDebts.show', $debt)
            ->with('success', 'Hutang supplier berhasil dicatat.');
    }

    public function show(SupplierDebt $supplierDebt): Response
    {
        $supplierDebt->load([
            'vendor:id,name,contact,bank_name,bank_account_number,account_holder',
            'project:id,name',
            'creator:id,name',
            'payments' => fn ($q) => $q->latest('paid_date')->latest('id'),
            'payments.bankAccount:id,label',
            'payments.creator:id,name',
        ]);

        return Inertia::render('Finance/SupplierDebts/Show', [
            'debt' => $supplierDebt,
            'bankAccounts' => BankAccount::where('is_active', true)->orderBy('label')->get(['id', 'label']),
        ]);
    }

    public function storePayment(
        StoreSupplierDebtPaymentRequest $request,
        SupplierDebt $supplierDebt,
        SupplierDebtService $service,
    ): RedirectResponse {
        $service->recordPayment($supplierDebt, $request->validated(), $request->user());

        return back()->with('success', 'Pembayaran hutang berhasil dicatat.');
    }
}
