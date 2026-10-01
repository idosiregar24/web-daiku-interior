<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreAssetInstallmentPaymentRequest;
use App\Models\Asset;
use App\Models\BankAccount;
use App\Services\AssetInstallmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRD §4.7 "Aset & Cicilan" — Sprint 9 decision #8. Read by everyone on
 * §7.1's "Asset Inventory" row (CEO/PM/Finance/Logistics); payments are
 * finance transactions, so only Finance records them (route middleware).
 * The plan itself is edited by Logistics on the asset form. No edit or
 * destroy — the payment ledger is append-only (PRD §9.4).
 */
class AssetInstallmentController extends Controller
{
    public function index(Request $request): Response
    {
        $assets = Asset::query()
            ->withInstallmentPlan()
            ->withPaidThisMonth()
            ->withMax('installmentPayments as last_paid_at', 'paid_at')
            ->search($request->string('search')->trim()->value() ?: null)
            ->byInstallmentStatus($request->string('status')->value() ?: null)
            // Still being paid off first, then by name.
            ->orderByRaw('CASE WHEN paid_install < total_install THEN 0 ELSE 1 END')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Asset $asset) => $asset->append('installment_status'));

        $plans = Asset::query()->withInstallmentPlan();
        $totalInstall = (float) (clone $plans)->sum('total_install');
        $totalPaid = (float) (clone $plans)->sum('paid_install');

        return Inertia::render('Finance/AssetInstallments/Index', [
            'assets' => $assets,
            'filters' => $request->only(['status', 'search']),
            'summary' => [
                'totalInstall' => $totalInstall,
                'totalPaid' => $totalPaid,
                'totalRemaining' => round($totalInstall - $totalPaid, 2),
                'outstandingCount' => Asset::query()->installmentOutstanding()->count(),
                'overdueCount' => Asset::query()->installmentOverdue()->count(),
                'dueThisMonth' => $this->dueThisMonth(),
            ],
            ...$this->paymentProps($request),
        ]);
    }

    public function show(Request $request, Asset $asset): Response
    {
        abort_unless($asset->has_installment, 404);

        $asset->load([
            'installmentPayments' => fn ($query) => $query
                ->with(['bankAccount:id,label', 'creator:id,name'])
                ->latest('paid_at')
                ->latest('id'),
        ])->append('installment_status');

        return Inertia::render('Finance/AssetInstallments/Show', [
            'asset' => $asset,
            ...$this->paymentProps($request),
        ]);
    }

    public function storePayment(
        StoreAssetInstallmentPaymentRequest $request,
        Asset $asset,
        AssetInstallmentService $service,
    ): RedirectResponse {
        $service->recordPayment($asset, $request->validated(), $request->user());

        return back()->with('success', 'Pembayaran cicilan aset berhasil dicatat.');
    }

    /**
     * Installments still expected this month: every plan with something
     * left that hasn't been paid this month, at its planned amount (capped
     * at what's left; the whole remainder when no amount is planned).
     */
    private function dueThisMonth(): float
    {
        return round((float) Asset::query()
            ->installmentOutstanding()
            ->withPaidThisMonth()
            ->get()
            ->reject(fn (Asset $asset) => (bool) $asset->paid_this_month)
            ->sum(fn (Asset $asset) => $asset->installment_amount === null
                ? (float) $asset->remaining_install
                : min((float) $asset->installment_amount, (float) $asset->remaining_install)), 2);
    }

    /** @return array{canPay: bool, bankAccounts: Collection<int, BankAccount>|array{}} */
    private function paymentProps(Request $request): array
    {
        $canPay = $request->user()->hasAnyRole(['FINANCE', 'SUPERADMIN']);

        return [
            'canPay' => $canPay,
            'bankAccounts' => $canPay
                ? BankAccount::where('is_active', true)->orderBy('label')->get(['id', 'label'])
                : [],
        ];
    }
}
