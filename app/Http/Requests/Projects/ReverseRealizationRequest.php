<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

/** Sprint 12 #27 — cancelling a realisation with a correction row (the project's PM only). */
class ReverseRealizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageBudget', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.max' => 'Catatan maksimal 1000 karakter.',
        ];
    }
}
