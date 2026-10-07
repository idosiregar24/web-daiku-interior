<?php

namespace App\Http\Requests\MasterData;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Sprint 16 Sub 08 — Master Kota (store & update); route-gated to SUPERADMIN. */
class CityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => preg_replace('/\s+/', ' ', trim((string) $this->input('name'))),
            'province' => trim((string) $this->input('province')) ?: null,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('cities', 'name')->ignore($this->route('city'))],
            'province' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama kota wajib diisi.',
            'name.unique' => 'Kota ini sudah ada di daftar.',
        ];
    }
}
