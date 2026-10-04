<?php

namespace App\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create/update a division (Sprint 10 decision #10). `is_active` only on update. */
class DivisionRequest extends FormRequest
{
    /** Route-level `role:HR` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $updating = $this->route('division') !== null;

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('divisions', 'name')->ignore($this->route('division'))],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => [$updating ? 'required' : 'prohibited', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama divisi wajib diisi.',
            'name.max' => 'Nama divisi maksimal 100 karakter.',
            'name.unique' => 'Divisi ini sudah ada.',
            'sort_order.integer' => 'Urutan harus berupa angka.',
            'sort_order.min' => 'Urutan tidak boleh negatif.',
            'is_active.required' => 'Status aktif wajib diisi.',
        ];
    }
}
