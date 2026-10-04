<?php

namespace App\Http\Requests\MasterData;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaterialSynonymRequest extends FormRequest
{
    /** Route-level `role:SUPERADMIN` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    /** Both words are stored the way match keys see them: lower case, single spaces. */
    protected function prepareForValidation(): void
    {
        $normalize = fn ($value) => is_string($value) ? mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? '')) : $value;

        $this->merge([
            'term' => $normalize($this->input('term')),
            'canonical' => $normalize($this->input('canonical')),
        ]);
    }

    public function rules(): array
    {
        return [
            'term' => ['required', 'string', 'max:50', 'different:canonical', Rule::unique('material_synonyms', 'term')->ignore($this->route('material_synonym'))],
            'canonical' => ['required', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'term.required' => 'Kata wajib diisi.',
            'term.unique' => 'Kata ini sudah punya sinonim.',
            'term.different' => 'Kata dan padanannya tidak boleh sama.',
            'canonical.required' => 'Padanan wajib diisi.',
        ];
    }
}
