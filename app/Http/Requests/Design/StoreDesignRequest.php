<?php

namespace App\Http\Requests\Design;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDesignRequest extends FormRequest
{
    /** Route-level `role:DESIGNER` middleware already gates this action (PRD §7.1 "Design Brief" — DES has CRUD). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pic_id' => ['required', 'exists:users,id'],
            'jenis_project' => ['nullable', Rule::in([
                'TOKO', 'CAFE', 'RENOVASI', 'KAMAR_SET', 'KITCHEN_SET',
                'KANTOR', 'ARSITEKTURAL', 'RUANG_TAMU_TV', 'RETAIL_TOKO', 'LAINNYA',
            ])],
            'target_hari' => ['nullable', 'integer', 'min:1'],
            'start_date' => ['nullable', 'date'],
            'brief_note' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'pic_id.required' => 'PIC utama wajib di-assign saat proyek desain dibuat.',
            'pic_id.exists' => 'PIC yang dipilih tidak ditemukan.',
        ];
    }
}
