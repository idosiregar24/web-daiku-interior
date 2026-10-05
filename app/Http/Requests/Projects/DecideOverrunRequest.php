<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Sprint 12 #28 — the CEO approves or rejects a held realisation (route `role:CEO`); a rejection needs a note. */
class DecideOverrunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'required_if:decision,reject', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.required_if' => 'Catatan penolakan wajib diisi.',
        ];
    }
}
