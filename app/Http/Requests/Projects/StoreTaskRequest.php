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
    /** Route-level `role:PM|ASISTEN_PM|QA` + ProjectPolicy::manageTargets() in the controller (Sprint 24 D6). */
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
            // that hasn't already passed QA, and an active tukang. Sprint 24
            // D6: a daily target always sits in a timeline (its day inside
            // the timeline's range — TaskService::create()).
            'milestone_id' => [
                'required',
                'integer',
                function (string $attribute, mixed $value, Closure $fail) use ($project) {
                    $milestone = Milestone::find($value);

                    if (! $milestone || (int) $milestone->project_id !== (int) $project->id) {
                        $fail('Timeline harus milik proyek ini.');
                    } elseif ($milestone->status === MilestoneStatus::Completed) {
                        $fail('Timeline ini sudah selesai (lolos QA) — tidak bisa ditambah task baru.');
                    }
                },
            ],
            // Sprint 38 — the RAB item it works on: an item of this project
            // (TaskService checks it is one of the timeline's). Left out → the
            // timeline's only item with a wage, if it has exactly one.
            'project_work_item_id' => ['nullable', 'integer', Rule::exists('project_work_items', 'id')->where('project_id', $project->id)],
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
            'milestone_id.required' => 'Pilih timeline daily target ini.',
            'milestone_id.integer' => 'Timeline tidak valid.',
            'project_work_item_id.integer' => 'Item RAB tidak valid.',
            'project_work_item_id.exists' => 'Item RAB harus milik proyek ini.',
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
