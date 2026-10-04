<?php

namespace App\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create/update a position (Sprint 10 decision #10): the name is unique
 * within its division. `is_active` only on update.
 */
class PositionRequest extends FormRequest
{
    /** Route-level `role:HR` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $position = $this->route('position');

        return [
            'division_id' => ['required', 'integer', Rule::exists('divisions', 'id')],
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('positions', 'name')
                    ->where('division_id', $this->integer('division_id'))
                    ->ignore($position),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => [$position ? 'required' : 'prohibited', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'division_id.required' => 'Divisi wajib dipilih.',
            'division_id.exists' => 'Divisi tidak ditemukan.',
            'name.required' => 'Nama jabatan wajib diisi.',
            'name.max' => 'Nama jabatan maksimal 100 karakter.',
            'name.unique' => 'Jabatan ini sudah ada di divisi tersebut.',
            'sort_order.integer' => 'Urutan harus berupa angka.',
            'sort_order.min' => 'Urutan tidak boleh negatif.',
            'is_active.required' => 'Status aktif wajib diisi.',
        ];
    }
}
