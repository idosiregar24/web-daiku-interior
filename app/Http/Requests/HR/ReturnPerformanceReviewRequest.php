<?php

namespace App\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;

/** SDM (Sprint 10, §3.4) — the CEO returns a SUBMITTED review to HR; the note is required. */
class ReturnPerformanceReviewRequest extends FormRequest
{
    /** Route-level `role:CEO` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.required' => 'Catatan pengembalian wajib diisi.',
            'note.max' => 'Catatan maksimal 1000 karakter.',
        ];
    }
}
