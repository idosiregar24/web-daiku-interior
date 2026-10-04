<?php

namespace App\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;

/** SDM (Sprint 10, §3.2): the CEO's rejection must say why. */
class RejectSalaryChangeRequest extends FormRequest
{
    /** Route-level `role:CEO` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reject_note' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reject_note.required' => 'Alasan penolakan wajib diisi.',
            'reject_note.max' => 'Alasan penolakan maksimal 1000 karakter.',
        ];
    }
}
