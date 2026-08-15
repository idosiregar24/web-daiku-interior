<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskStatusRequest extends FormRequest
{
    /** Ownership (PM any task, Field Staff own task only) is enforced by TaskPolicy::updateStatus() in the controller, not here. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // OVER excluded on purpose — system-computed only (PRD §4.5),
            // never a manual choice. See TaskService::updateStatus().
            'status' => ['required', Rule::in(['PENDING', 'ONPROGRESS', 'PENGECEKAN', 'DONE'])],
            'kendala' => ['nullable', 'string'],
            'note' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Status wajib dipilih.',
        ];
    }
}
