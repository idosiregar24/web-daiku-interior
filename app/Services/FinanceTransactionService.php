<?php

namespace App\Services;

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Enums\TaskStatus;
use App\Models\FinanceTransaction;
use App\Models\Task;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * PRD §4.7 "Finance – Transaction" row (Finance CRUD, CEO/PM read). The
 * broader Finance module (multi-rekening allocation config, staff loans,
 * supplier debts, assets) is out of scope for Sprint 4 — see
 * .claude/plan/README.md.
 */
class FinanceTransactionService
{
    public function create(array $data, User $actor): FinanceTransaction
    {
        return FinanceTransaction::create([
            'project_id' => $data['project_id'] ?? null,
            'bank_account_id' => $data['bank_account_id'],
            'type' => $data['type'],
            'kategori' => $data['kategori'],
            'amount' => $data['amount'],
            'description' => $data['description'],
            'date' => $data['date'],
            'created_by' => $actor->id,
        ]);
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

        return FinanceTransaction::create([
            'project_id' => $task->project_id,
            'type' => FinanceTransactionType::Expense->value,
            'kategori' => FinanceCategory::GajiKaryawan->value,
            'amount' => $task->rate_per_task,
            'description' => "Upah task \"{$task->title}\" — {$task->assignee?->name}",
            'reference_id' => $task->id,
            'date' => now()->toDateString(),
            'created_by' => $actor->id,
        ]);
    }

    public function isTaskPaid(Task $task): bool
    {
        return FinanceTransaction::query()
            ->where('reference_id', $task->id)
            ->where('kategori', FinanceCategory::GajiKaryawan->value)
            ->exists();
    }
}
