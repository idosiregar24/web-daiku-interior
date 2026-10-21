<?php

namespace App\Http\Requests\Logistics;

use App\Enums\ProjectMaterialSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sprint 29 Sub 01 (L1/K5) — "Simpan semua": several catalog lines in one
 * request, each with the same rules as StoreProjectMaterialRequest.
 */
class StoreProjectMaterialsRequest extends FormRequest
{
    public const MAX_LINES = 50;

    /** Route-level role middleware + ProjectPolicy::planMaterials() (in the controller) gate this. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*.material_id' => ['required', 'integer', 'exists:materials,id'],
            'lines.*.source' => ['required', Rule::in([ProjectMaterialSource::Gudang->value, ProjectMaterialSource::Pembelian->value])],
            'lines.*.qty_planned' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:1000000'],
            'lines.*.vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->where('is_active', true)],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'Tambahkan minimal satu bahan.',
            'lines.min' => 'Tambahkan minimal satu bahan.',
            'lines.max' => 'Maksimal '.self::MAX_LINES.' bahan sekali simpan.',
            'lines.*.material_id.required' => 'Baris :position: material wajib dipilih.',
            'lines.*.material_id.exists' => 'Baris :position: material tidak ditemukan.',
            'lines.*.source.required' => 'Baris :position: sumber material wajib dipilih.',
            'lines.*.source.in' => 'Baris :position: sumber material harus Gudang atau Pembelian.',
            'lines.*.qty_planned.required' => 'Baris :position: jumlah kebutuhan wajib diisi.',
            'lines.*.qty_planned.numeric' => 'Baris :position: jumlah kebutuhan harus berupa angka.',
            'lines.*.qty_planned.min' => 'Baris :position: jumlah kebutuhan minimal 0,01.',
            'lines.*.qty_planned.max' => 'Baris :position: jumlah kebutuhan terlalu besar.',
            'lines.*.qty_planned.decimal' => 'Baris :position: jumlah maksimal 2 angka di belakang koma.',
            'lines.*.vendor_id.exists' => 'Baris :position: vendor tidak ditemukan atau sudah nonaktif.',
        ];
    }
}
