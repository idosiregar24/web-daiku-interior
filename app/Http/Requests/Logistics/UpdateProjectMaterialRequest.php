<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectMaterialRequest extends FormRequest
{
    /** Route-level role middleware + ProjectPolicy::manageMaterials() (in the controller) gate this. */
    public function authorize(): bool
    {
        return true;
    }

    /** The item and source can't be swapped — remove the line and add another instead. */
    public function rules(): array
    {
        return [
            'qty_planned' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:1000000'],
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->where('is_active', true)],
        ];
    }

    public function messages(): array
    {
        return [
            'qty_planned.required' => 'Jumlah kebutuhan wajib diisi.',
            'qty_planned.decimal' => 'Jumlah maksimal 2 angka di belakang koma.',
            'vendor_id.exists' => 'Vendor tidak ditemukan atau sudah nonaktif.',
        ];
    }
}
