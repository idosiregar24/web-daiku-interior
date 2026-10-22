<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

/** Sprint 32 — the default weights of the positions (route `role:CEO`). */
class SaveCareerLevelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'levels' => ['required', 'array', 'max:30'],
            'levels.*.id' => ['required', 'integer', 'exists:career_levels,id'],
            'levels.*.name' => ['required', 'string', 'max:60'],
            'levels.*.weight' => ['required', 'numeric', 'gt:0', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'levels.*.name.required' => 'Nama tingkatan wajib diisi.',
            'levels.*.weight.required' => 'Bobot wajib diisi.',
            'levels.*.weight.gt' => 'Bobot harus lebih dari 0.',
        ];
    }
}
