<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDailyTaskFormRequest extends FormRequest
{
    /** Route-level `role:FIELD_STAFF` middleware gates who can reach this; DailyTaskFormService::store() checks the task is actually theirs. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // OVER excluded — same reasoning as UpdateTaskStatusRequest,
            // system-computed only.
            'status' => ['required', Rule::in(['PENDING', 'ONPROGRESS', 'PENGECEKAN', 'DONE'])],
            'kendala' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Status wajib dipilih.',
        ];
    }
}
