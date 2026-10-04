<?php

namespace App\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;

/** SDM (Sprint 10, §3.1): cancelling a record = a PEMBATALAN entry with a reason. */
class VoidDisciplinaryRecordRequest extends FormRequest
{
    /** Route-level `role:HR` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan pembatalan wajib diisi.',
            'reason.min' => 'Alasan pembatalan minimal 5 karakter.',
            'reason.max' => 'Alasan pembatalan maksimal 1000 karakter.',
        ];
    }
}
