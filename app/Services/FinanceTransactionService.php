<?php

namespace App\Services;

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Enums\TaskStatus;
use App\Models\FinanceTransaction;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PRD §4.7 "Finance – Transaction" row (Finance CRUD, CEO/PM read). The
 * broader Finance module (multi-rekening allocation config, staff loans,
 * supplier debts, assets) is out of scope for Sprint 4 — see
 * .claude/plan/README.md.
 */
class FinanceTransactionService
{
    public function __construct(private AuditLogService $auditLogService) {}

    public function create(array $data, User $actor): FinanceTransaction
    {
        return DB::transaction(fn () => $this->audited('finance.transaction_created', FinanceTransaction::create([
            'project_id' => $data['project_id'] ?? null,
            'bank_account_id' => $data['bank_account_id'],
            'type' => $data['type'],
            'kategori' => $data['kategori'],
            'amount' => $data['amount'],
            'description' => $data['description'],
            'date' => $data['date'],
            'created_by' => $actor->id,
        ]), $actor));
    }

    /**
     * "Pencatatan upah tukang per task selesai" — pays a single DONE task
     * once, using its `rate_per_task`. "Already paid" is derived from
     * whether a GAJI_KARYAWAN FinanceTransaction already references this
     * task, rather than a new `is_paid` column on Task (Task stays
     * immutable per CLAUDE.md golden rule #6 — nothing here writes to it).
     */
    public function payStaffForTask(Task $task, User $actor): FinanceTransaction
    {
        if ($task->status !== TaskStatus::Done) {
            throw ValidationException::withMessages([
                'status' => 'Task ini belum DONE — upah belum bisa dicatat.',
            ]);
        }

        if (! $task->rate_per_task) {
            throw ValidationException::withMessages([
                'status' => 'Task ini tidak punya rate_per_task.',
            ]);
        }

        if ($this->isTaskPaid($task)) {
            throw ValidationException::withMessages([
                'status' => 'Upah untuk task ini sudah pernah dicatat.',
            ]);
        }

        return DB::transaction(fn () => $this->audited('finance.staff_paid', FinanceTransaction::create([
            'project_id' => $task->project_id,
            'type' => FinanceTransactionType::Expense->value,
            'kategori' => FinanceCategory::GajiKaryawan->value,
            'amount' => $task->rate_per_task,
            'description' => "Upah task \"{$task->title}\" — {$task->assignee?->name}",
            'reference_id' => $task->id,
            'date' => now()->toDateString(),
            'created_by' => $actor->id,
        ]), $actor));
    }

    /** PRD §9.4 "perubahan finance" — every transaction this service writes is audited with it. */
    private function audited(string $action, FinanceTransaction $transaction, User $actor): FinanceTransaction
    {
        $this->auditLogService->record(
            $action,
            $transaction,
            null,
            $transaction->only(['project_id', 'bank_account_id', 'type', 'kategori', 'amount', 'description', 'reference_id', 'date']),
            $actor,
        );

        return $transaction;
    }

    /**
     * Income vs expense per month for the last `$months` months (current
     * month included), zero-filled — Finance's cash flow dashboard and the
     * CEO's Cash Flow Summary widget. Grouped in PHP, not with SQL
     * DATE_FORMAT(): that function breaks the SQLite test connection
     * (database-standards.md §1, see .claude/plan/README.md Sprint 4).
     *
     * @return Collection<int, array{month: string, label: string, income: float, expense: float}>
     */
    public function monthlyCashFlow(int $months = 6): Collection
    {
        $from = now()->subMonths($months - 1)->startOfMonth();

        $rows = FinanceTransaction::query()
            ->select('date', 'type', 'amount')
            ->where('date', '>=', $from->toDateString())
            ->get()
            ->groupBy(fn (FinanceTransaction $row) => $row->date->format('Y-m'));

        return collect(range(0, $months - 1))
            ->map(fn (int $i) => now()->startOfMonth()->subMonths($months - 1 - $i)->format('Y-m'))
            ->map(function (string $month) use ($rows) {
                $monthRows = $rows->get($month, collect());

                return [
                    'month' => $month,
                    'label' => Carbon::createFromFormat('Y-m', $month)->translatedFormat('M Y'),
                    'income' => (float) $monthRows->where('type', FinanceTransactionType::Income)->sum('amount'),
                    'expense' => (float) $monthRows->where('type', FinanceTransactionType::Expense)->sum('amount'),
                ];
            })
            ->values();
    }

    public function isTaskPaid(Task $task): bool
    {
        return FinanceTransaction::query()
            ->where('reference_id', $task->id)
            ->where('kategori', FinanceCategory::GajiKaryawan->value)
            ->exists();
    }
}
