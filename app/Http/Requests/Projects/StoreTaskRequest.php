<?php

namespace App\Http\Requests\Projects;

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
        return [
            'milestone_id' => ['nullable', 'exists:milestones,id'],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'assignee_id' => ['required', 'exists:users,id'],
            'due_date' => ['required', 'date'],
            'priority' => ['nullable', Rule::in(['HIGH', 'MEDIUM', 'LOW'])],
            'rate_per_task' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Judul task wajib diisi.',
            'assignee_id.required' => 'Task harus di-assign ke salah satu tukang.',
            'assignee_id.exists' => 'Tukang yang dipilih tidak ditemukan.',
            'due_date.required' => 'Tanggal jatuh tempo wajib diisi.',
        ];
    }
}
