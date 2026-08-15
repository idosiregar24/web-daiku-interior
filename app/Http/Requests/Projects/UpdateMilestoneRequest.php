<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMilestoneRequest extends FormRequest
{
    /** Route-level `role:CEO|PM` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'target_date' => ['required', 'date'],
            'status' => ['required', Rule::in(['PENDING', 'IN_PROGRESS', 'QA_WAITING', 'COMPLETED', 'OVERDUE'])],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama milestone wajib diisi.',
            'target_date.required' => 'Target tanggal wajib diisi.',
            'status.required' => 'Status wajib dipilih.',
        ];
    }
}
