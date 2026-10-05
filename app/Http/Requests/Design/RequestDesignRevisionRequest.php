<?php

namespace App\Http\Requests\Design;

use Illuminate\Foundation\Http\FormRequest;

/** Sprint 12 decision #17 — Marketing's "Minta Revisi" (route `role:MARKETING`): what the client wants changed. */
class RequestDesignRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.required' => 'Catatan revisi wajib diisi.',
            'note.min' => 'Catatan revisi minimal 5 karakter.',
            'note.max' => 'Catatan revisi maksimal 2000 karakter.',
        ];
    }
}
