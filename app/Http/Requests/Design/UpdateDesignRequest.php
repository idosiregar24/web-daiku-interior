<?php

namespace App\Http\Requests\Design;

use App\Enums\DesignStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateDesignRequest extends FormRequest
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
            'status' => ['required', new Enum(DesignStatus::class)],
            'target_hari' => ['nullable', 'integer', 'min:1'],
            'start_date' => ['nullable', 'date'],
            'brief_note' => ['nullable', 'string'],
            'problem' => ['nullable', 'string'],
            'design_urls' => ['nullable', 'array'],
            'design_urls.*' => ['url', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'pic_id.required' => 'PIC utama wajib diisi.',
            'pic_id.exists' => 'PIC yang dipilih tidak ditemukan.',
            'status.required' => 'Status wajib dipilih.',
            'design_urls.*.url' => 'Link desain harus berupa URL yang valid.',
        ];
    }
}
