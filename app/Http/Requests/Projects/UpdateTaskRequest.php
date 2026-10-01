<?php

namespace App\Http\Requests\Projects;

use App\Enums\MilestoneStatus;
use App\Models\Milestone;
use App\Models\Task;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
{
    /**
     * Route-level `role:PM` plus TaskPolicy::update() in the controller
     * (Field Staff never edit task content — CLAUDE.md golden rule #6).
     * Whether this task is still editable at all (closed project, paid or
     * DONE task) is TaskService::update()'s business rule.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Task $task */
        $task = $this->route('task');

        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'due_date' => ['required', 'date'],
            'priority' => ['required', Rule::in(['HIGH', 'MEDIUM', 'LOW'])],
            // Same project only; moving a task *into* a milestone that already
            // passed QA makes no sense, but a task already sitting in one can
            // keep it while other fields are edited.
            'milestone_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, Closure $fail) use ($task) {
                    $milestone = Milestone::find($value);

                    if (! $milestone || (int) $milestone->project_id !== (int) $task->project_id) {
                        $fail('Milestone harus milik proyek yang sama dengan task ini.');
                    } elseif ((int) $value !== (int) $task->milestone_id && $milestone->status === MilestoneStatus::Completed) {
                        $fail('Milestone ini sudah COMPLETED (lolos QA) — task tidak bisa dipindah ke sana.');
                    }
                },
            ],
            // A deactivated tukang may keep a task they already hold; any new
            // assignee must be an active Field Staff.
            'assignee_id' => [
                'required',
                'integer',
                function (string $attribute, mixed $value, Closure $fail) use ($task) {
                    if ((int) $value === (int) $task->assignee_id) {
                        return;
                    }

                    $staff = User::find($value);

                    if (! $staff || ! $staff->hasRole('FIELD_STAFF')) {
                        $fail('Task hanya bisa di-assign ke tukang (Field Staff).');
                    } elseif (! $staff->is_active) {
                        $fail('Tukang ini sudah nonaktif — pilih tukang lain.');
                    }
                },
            ],
            'rate_per_task' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Judul task wajib diisi.',
            'title.max' => 'Judul task maksimal 150 karakter.',
            'due_date.required' => 'Tanggal jatuh tempo wajib diisi.',
            'due_date.date' => 'Tanggal jatuh tempo tidak valid.',
            'priority.required' => 'Prioritas wajib dipilih.',
            'priority.in' => 'Prioritas tidak valid.',
            'milestone_id.integer' => 'Milestone tidak valid.',
            'assignee_id.required' => 'Task harus di-assign ke salah satu tukang.',
            'assignee_id.integer' => 'Tukang tidak valid.',
            'rate_per_task.numeric' => 'Rate harus berupa angka.',
            'rate_per_task.min' => 'Rate tidak boleh negatif.',
            'rate_per_task.max' => 'Rate terlalu besar.',
        ];
    }
}
