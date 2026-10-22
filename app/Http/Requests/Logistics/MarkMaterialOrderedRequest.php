<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sprint 29 Sub 11 (E3) — "Tandai Dipesan" on approved bought lines: the
 * expected arrival date, optionally the vendor and a note.
 */
class MarkMaterialOrderedRequest extends FormRequest
{
    public const MAX_LINES = 100;

    /** Route-level `role:LOGISTICS` gates this. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'ids.*' => ['required', 'integer', 'distinct'],
            'expected_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->where('is_active', true)],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Pilih minimal satu bahan.',
            'ids.min' => 'Pilih minimal satu bahan.',
            'ids.max' => 'Maksimal '.self::MAX_LINES.' bahan sekali proses.',
            'expected_at.required' => 'Perkiraan tiba wajib diisi.',
            'expected_at.date_format' => 'Perkiraan tiba harus berupa tanggal.',
            'expected_at.after_or_equal' => 'Perkiraan tiba tidak boleh sebelum hari ini.',
            'vendor_id.exists' => 'Vendor tidak ditemukan atau sudah nonaktif.',
            'note.max' => 'Catatan maksimal 500 karakter.',
        ];
    }
}
