<?php

namespace App\Http\Controllers\Finance;

use App\Enums\FinanceCategory;
use App\Enums\TaskStatus;
use App\Exports\CashFlowExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\FinanceDashboardRequest;
use App\Http\Requests\Finance\FinanceTransactionFilterRequest;
use App\Http\Requests\Finance\PayStaffRequest;
use App\Http\Requests\Finance\StoreFinanceTransactionRequest;
use App\Models\BankAccount;
use App\Models\FinanceTransaction;
use App\Models\Project;
use App\Models\Task;
use App\Services\FinanceTransactionService;
use App\Services\StaffPaymentService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * PRD §7.1 "Finance – Transaction" row: CEO/PM read, Finance CRUD.
 */
class FinanceTransactionController extends Controller
{
    /**
     * "Transaksi list: filter by type/tanggal/proyek + total summary" —
     * plus rekening/kategori filters (Sprint 9). The summary leaves Pindah
     * Dana out unless the list is scoped to one account (see
     * FinanceTransactionService::isCompanyLevel()).
     */
    public function index(FinanceTransactionFilterRequest $request, FinanceTransactionService $service): Response
    {
        $filters = $request->filters();
        $scope = $this->withSalaryScope($filters, $request);

        $transactions = FinanceTransaction::query()
            ->with(['project:id,name', 'bankAccount:id,label', 'creator:id,name'])
            ->filter($scope)
            ->latest('date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Finance/Transactions/Index', [
            'transactions' => $transactions,
            'filters' => $filters,
            'summary' => $service->summarize($scope),
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            // Every account for the filter (inactive ones keep their history);
            // the "Catat Transaksi"/"Pindah Dana" dialogs only offer active ones.
            'bankAccounts' => BankAccount::query()
                ->select(['id', 'label', 'opening_balance', 'is_active'])
                ->withBalance()
                ->orderBy('label')
                ->get()
                ->append('current_balance'),
            // "Catat Transaksi" hides these — same list the Form Request rejects.
            'systemManagedCategories' => array_map(fn (FinanceCategory $category) => $category->value, FinanceCategory::systemManaged()),
        ]);
    }

    public function store(StoreFinanceTransactionRequest $request, FinanceTransactionService $service): RedirectResponse
    {
        $service->create($request->validated(), $request->user());

        return back()->with('success', 'Transaksi berhasil dicatat.');
    }

    /**
     * "Cash flow dashboard: chart pemasukan vs pengeluaran 6 bulan"
     * (Recharts bar chart, frontend) + PRD §4.7 "Ringkasan pemasukan dan
     * pengeluaran per rekening dan keseluruhan" for a chosen month. Both
     * lazy, so the month picker's partial reload (`only:
     * ['accountSummary']`) doesn't recompute the chart.
     */
    public function dashboard(FinanceDashboardRequest $request, FinanceTransactionService $service): Response
    {
        return Inertia::render('Finance/Dashboard', [
            'cashFlow' => fn () => $service->monthlyCashFlow(6),
            'accountSummary' => fn () => $service->accountSummary($request->month()),
        ]);
    }

    /**
     * PRD §4.7 "Export Excel: Laporan cash flow per bulan, per proyek, per
     * rekening" — same filters as the Transactions page; none = the last
     * 6 months (see CashFlowExport).
     */
    public function exportExcel(FinanceTransactionFilterRequest $request): BinaryFileResponse
    {
        $filters = $this->withSalaryScope($request->filters(), $request);

        return Excel::download(new CashFlowExport($filters), 'cash-flow-'.now()->format('Y-m').'.xlsx');
    }

    /**
     * Salaries are CEO/FINANCE-only (sprint-09 decision #7): every other
     * reader of the list/export gets payroll legs filtered out. Added
     * server-side, never accepted from the query string.
     */
    private function withSalaryScope(array $filters, FinanceTransactionFilterRequest $request): array
    {
        return FinanceTransaction::canSeeSalaries($request->user())
            ? $filters
            : $filters + ['hide_salary_payments' => true];
    }

    /** "Pencatatan upah tukang per task selesai + staff payment list". */
    public function staffPayments(StaffPaymentService $service): Response
    {
        $tasks = Task::query()
            ->with(['assignee:id,name', 'project:id,name'])
            ->where('status', TaskStatus::Done->value)
            ->whereNotNull('rate_per_task')
            ->whereNotIn('id', FinanceTransaction::query()
                ->where('kategori', 'GAJI_KARYAWAN')
                ->whereNotNull('reference_id')
                ->pluck('reference_id'))
            ->latest('completed_at')
            ->paginate(20)
            ->withQueryString();

        // PRD §4.7 — show the loan installment that will be deducted, so
        // Finance sees the net transfer before confirming.
        $reserved = [];
        foreach ($tasks->getCollection() as $task) {
            $task->setAttribute('payment_preview', $service->preview($task, $reserved));
        }

        return Inertia::render('Finance/StaffPayments/Index', [
            'tasks' => $tasks,
            'bankAccounts' => BankAccount::where('is_active', true)->orderBy('label')->get(['id', 'label']),
        ]);
    }

    public function payStaff(PayStaffRequest $request, Task $task, StaffPaymentService $service): RedirectResponse
    {
        $service->pay($task, $request->integer('bank_account_id'), $request->user());

        return back()->with('success', 'Upah tukang berhasil dicatat.');
    }
}
