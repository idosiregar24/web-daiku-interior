<?php

namespace App\Http\Requests\Projects;

use App\Http\Requests\Concerns\ValidatesRabReferences;
use Illuminate\Foundation\Http\FormRequest;

/** Sprint 12 #29 — "Minta RAB Tambahan": Marketing or the project's PM (ProjectPolicy::requestAddendum()); what's to be added is required. */
class RequestAddendumRequest extends FormRequest
{
    use ValidatesRabReferences;

    public function authorize(): bool
    {
        return $this->user()->can('requestAddendum', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'min:5', 'max:2000'],
            // Sprint 14 Sub 01 — references for the Estimator.
            ...$this->referenceRules(),
        ];
    }

    public function messages(): array
    {
        return [
            'note.required' => 'Jelaskan pekerjaan tambah yang diminta.',
            'note.min' => 'Catatan minimal 5 karakter.',
            'note.max' => 'Catatan maksimal 2000 karakter.',
            ...$this->referenceMessages(),
        ];
    }
}
