<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

/** Sprint 29 Sub 04 (F2) — "Kembalikan": the Pengawas says why the target isn't done yet. */
class ReturnTaskRequest extends FormRequest
{
    /** Route-level role middleware + TaskPolicy::review() (in the controller) gate this. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.required' => 'Tulis alasan target dikembalikan.',
            'note.min' => 'Alasan minimal 3 karakter.',
            'note.max' => 'Alasan maksimal 500 karakter.',
        ];
    }
}
