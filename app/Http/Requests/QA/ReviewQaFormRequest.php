<?php

namespace App\Http\Requests\QA;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewQaFormRequest extends FormRequest
{
    /** Route-level `role:QA` middleware already gates this action (PRD §7.1 "QA Form" row — QA has CRUD). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'checklist_data' => ['required', 'array', 'min:1'],
            'checklist_data.*.label' => ['required', 'string'],
            'checklist_data.*.passed' => ['required', 'boolean'],
            'checklist_data.*.note' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'required_if:decision,reject'],
        ];
    }

    public function messages(): array
    {
        return [
            'decision.required' => 'Keputusan wajib dipilih.',
            'notes.required_if' => 'Catatan perbaikan wajib diisi saat reject.',
        ];
    }
}
