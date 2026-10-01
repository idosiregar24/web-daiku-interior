<?php

namespace App\Http\Requests\Projects;

use App\Enums\MilestoneStatus;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    /** Route-level `role:PM` middleware already gates this action (PRD §7.1 "Task – Create/Edit" — PM has CRUD). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');

        return [
            // Same rules as UpdateTaskRequest: a milestone of this project
            // that hasn't already passed QA, and an active tukang.
            'milestone_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, Closure $fail) use ($project) {
                    $milestone = Milestone::find($value);

                    if (! $milestone || (int) $milestone->project_id !== (int) $project->id) {
                        $fail('Milestone harus milik proyek ini.');
                    } elseif ($milestone->status === MilestoneStatus::Completed) {
                        $fail('Milestone ini sudah COMPLETED (lolos QA) — tidak bisa ditambah task baru.');
                    }
                },
            ],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'assignee_id' => [
                'required',
                'integer',
                function (string $attribute, mixed $value, Closure $fail) {
                    $staff = User::find($value);

                    if (! $staff) {
                        $fail('Tukang yang dipilih tidak ditemukan.');
                    } elseif (! $staff->hasRole('FIELD_STAFF')) {
                        $fail('Task hanya bisa di-assign ke tukang (Field Staff).');
                    } elseif (! $staff->is_active) {
                        $fail('Tukang ini sudah nonaktif — pilih tukang lain.');
                    }
                },
            ],
            'due_date' => ['required', 'date'],
            'priority' => ['nullable', Rule::in(['HIGH', 'MEDIUM', 'LOW'])],
            'rate_per_task' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ];
    }

    public function messages(): array
    {
        return [
            'milestone_id.integer' => 'Milestone tidak valid.',
            'title.required' => 'Judul task wajib diisi.',
            'title.max' => 'Judul task maksimal 150 karakter.',
            'assignee_id.required' => 'Task harus di-assign ke salah satu tukang.',
            'assignee_id.integer' => 'Tukang tidak valid.',
            'due_date.required' => 'Tanggal jatuh tempo wajib diisi.',
            'rate_per_task.numeric' => 'Rate harus berupa angka.',
            'rate_per_task.min' => 'Rate tidak boleh negatif.',
            'rate_per_task.max' => 'Rate terlalu besar.',
        ];
    }
}
