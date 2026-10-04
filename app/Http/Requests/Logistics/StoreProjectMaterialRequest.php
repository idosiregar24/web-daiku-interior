<?php

namespace App\Http\Requests\Logistics;

use App\Enums\ProjectMaterialSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectMaterialRequest extends FormRequest
{
    /** Route-level role middleware + ProjectPolicy::planMaterials() (in the controller) gate this. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Planning picks a catalog item, taken from stock (GUDANG) or bought
     * for the project (PEMBELIAN). CUSTOM lines only come from an
     * approved out-of-catalog request (Sub 4), never from here.
     */
    public function rules(): array
    {
        return [
            'material_id' => ['required', 'integer', 'exists:materials,id'],
            'source' => ['required', Rule::in([ProjectMaterialSource::Gudang->value, ProjectMaterialSource::Pembelian->value])],
            'qty_planned' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:1000000'],
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->where('is_active', true)],
        ];
    }

    public function messages(): array
    {
        return [
            'material_id.required' => 'Material wajib dipilih.',
            'source.required' => 'Sumber material wajib dipilih.',
            'source.in' => 'Sumber material harus Gudang atau Pembelian.',
            'qty_planned.required' => 'Jumlah kebutuhan wajib diisi.',
            'qty_planned.min' => 'Jumlah kebutuhan minimal 0,01.',
            'qty_planned.decimal' => 'Jumlah maksimal 2 angka di belakang koma.',
            'vendor_id.exists' => 'Vendor tidak ditemukan atau sudah nonaktif.',
        ];
    }
}
