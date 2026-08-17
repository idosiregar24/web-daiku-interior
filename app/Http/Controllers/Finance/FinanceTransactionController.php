<?php

namespace App\Http\Controllers\Finance;

use App\Enums\FinanceTransactionType;
use App\Enums\TaskStatus;
use App\Exports\CashFlowExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinanceTransactionRequest;
use App\Models\BankAccount;
use App\Models\FinanceTransaction;
use App\Models\Project;
use App\Models\Task;
use App\Services\FinanceTransactionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * PRD §7.1 "Finance – Transaction" row: CEO/PM read, Finance CRUD.
 */
class FinanceTransactionController extends Controller
{
    public function index(Request $request): Response
    {
        $transactions = FinanceTransaction::query()
            ->with(['project:id,name', 'bankAccount:id,label', 'creator:id,name'])
            ->byType($request->string('type')->value() ?: null)
            ->byProject($request->integer('project_id') ?: null)
            ->when($request->date('from'), fn ($q, $from) => $q->whereDate('date', '>=', $from))
            ->when($request->date('to'), fn ($q, $to) => $q->whereDate('date', '<=', $to))
            ->latest('date')
            ->paginate(20)
            ->withQueryString();

        $summaryQuery = FinanceTransaction::query()
            ->byType($request->string('type')->value() ?: null)
            ->byProject($request->integer('project_id') ?: null)
            ->when($request->date('from'), fn ($q, $from) => $q->whereDate('date', '>=', $from))
            ->when($request->date('to'), fn ($q, $to) => $q->whereDate('date', '<=', $to));

        $totalIncome = (float) (clone $summaryQuery)->where('type', 'PEMASUKAN')->sum('amount');
        $totalExpense = (float) (clone $summaryQuery)->where('type', 'PENGELUARAN')->sum('amount');

        return Inertia::render('Finance/Transactions/Index', [
            'transactions' => $transactions,
            'filters' => $request->only(['type', 'project_id', 'from', 'to']),
            'totalIncome' => $totalIncome,
            'totalExpense' => $totalExpense,
            'balance' => $totalIncome - $totalExpense,
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            'bankAccounts' => BankAccount::where('is_active', true)->orderBy('label')->get(['id', 'label']),
        ]);
    }

    public function store(StoreFinanceTransactionRequest $request, FinanceTransactionService $service): RedirectResponse
    {
        $service->create($request->validated(), $request->user());

        return back()->with('success', 'Transaksi berhasil dicatat.');
    }

    /**
     * "Cash flow dashboard: chart pemasukan vs pengeluaran 6 bulan"
     * (Recharts bar chart, frontend). Grouped in PHP rather than
     * `DATE_FORMAT()` (MySQL-only — breaks the SQLite connection
     * `phpunit.xml` runs tests against, per database-standards.md §1
     * "jangan pakai fitur khusus" one engine can't share).
     */
    public function dashboard(): Response
    {
        $from = now()->subMonths(5)->startOfMonth();

        $rows = FinanceTransaction::query()
            ->select('date', 'type', 'amount')
            ->where('date', '>=', $from->toDateString())
            ->get()
            ->groupBy(fn (FinanceTransaction $row) => $row->date->format('Y-m'));

        $months = collect(range(0, 5))->map(fn ($i) => now()->subMonths(5 - $i)->format('Y-m'));

        $cashFlow = $months->map(function (string $month) use ($rows) {
            $monthRows = $rows->get($month, collect());

            return [
                'month' => $month,
                'label' => Carbon::createFromFormat('Y-m', $month)->translatedFormat('M Y'),
                'income' => (float) $monthRows->where('type', FinanceTransactionType::Income)->sum('amount'),
                'expense' => (float) $monthRows->where('type', FinanceTransactionType::Expense)->sum('amount'),
            ];
        });

        return Inertia::render('Finance/Dashboard', [
            'cashFlow' => $cashFlow,
        ]);
    }

    public function exportExcel(): BinaryFileResponse
    {
        return Excel::download(new CashFlowExport, 'cash-flow-'.now()->format('Y-m').'.xlsx');
    }

    /** "Pencatatan upah tukang per task selesai + staff payment list". */
    public function staffPayments(): Response
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

        return Inertia::render('Finance/StaffPayments/Index', [
            'tasks' => $tasks,
        ]);
    }

    public function payStaff(Task $task, FinanceTransactionService $service): RedirectResponse
    {
        $service->payStaffForTask($task, request()->user());

        return back()->with('success', 'Upah tukang berhasil dicatat.');
    }
}
