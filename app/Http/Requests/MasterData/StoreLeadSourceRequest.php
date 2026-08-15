<?php

namespace App\Http\Requests\MasterData;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeadSourceRequest extends FormRequest
{
    /** Route-level `role:SUPERADMIN` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:lead_sources,name'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama sumber lead wajib diisi.',
            'name.unique' => 'Sumber lead ini sudah ada.',
        ];
    }
}
