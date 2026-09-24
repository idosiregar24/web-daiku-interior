<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectMaterialRequest extends FormRequest
{
    /** Route-level role middleware gates this (see routes/web.php "Project Material"). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'material_id' => ['required', 'integer', 'exists:materials,id'],
            'qty_planned' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'material_id.required' => 'Material wajib dipilih.',
            'qty_planned.required' => 'Jumlah kebutuhan wajib diisi.',
            'qty_planned.min' => 'Jumlah kebutuhan minimal 1.',
        ];
    }
}
