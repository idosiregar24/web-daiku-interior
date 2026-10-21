<?php

namespace App\Http\Controllers\Finance;

use App\Enums\SupervisorPaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\PayStaffRequest;
use App\Models\BankAccount;
use App\Models\SupervisorPayment;
use App\Services\SupervisorPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 24 D4 — "Jasa Pengawas" on the Pembayaran Staf hub: Finance's
 * queue of payments the PM approved ("Bayar Pengawas: DP · Proyek X") and
 * the history. CEO reads; only Finance pays (route `role:FINANCE`).
 */
class SupervisorPaymentController extends Controller
{
    public function index(Request $request): Response
    {
        $paid = $request->query('status') === 'paid';

        $payments = SupervisorPayment::query()
            ->with(['project:id,name', 'supervisor:id,name', 'weeklyReport:id,week_number', 'decider:id,name', 'payer:id,name'])
            ->where('status', ($paid ? SupervisorPaymentStatus::Dibayar : SupervisorPaymentStatus::DisetujuiPm)->value)
            ->when($paid, fn ($query) => $query->latest('paid_at'), fn ($query) => $query->oldest('decided_at'))
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Finance/SupervisorPayments/Index', [
            'payments' => $payments,
            'filters' => ['status' => $paid ? 'paid' : 'to_pay'],
            'canPay' => $request->user()->hasAnyRole(['FINANCE', 'SUPERADMIN']),
            'bankAccounts' => BankAccount::where('is_active', true)->orderBy('label')->get(['id', 'label']),
        ]);
    }

    public function pay(PayStaffRequest $request, SupervisorPayment $supervisorPayment, SupervisorPaymentService $service): RedirectResponse
    {
        $service->pay($supervisorPayment, $request->integer('bank_account_id'), $request->user());

        return back()->with('success', 'Pembayaran upah tukang dicatat.');
    }
}
