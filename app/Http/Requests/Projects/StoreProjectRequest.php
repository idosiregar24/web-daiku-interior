<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    /** Route-level `role:CEO|PM` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lead_id' => ['required', 'exists:leads,id'],
            'name' => ['required', 'string', 'max:255'],
            'pm_id' => ['required', 'exists:users,id'],
            'start_date' => ['required', 'date'],
            'contract_value' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'lead_id.required' => 'Lead wajib dipilih.',
            'lead_id.exists' => 'Lead tidak ditemukan.',
            'name.required' => 'Nama proyek wajib diisi.',
            'pm_id.required' => 'Project Manager wajib dipilih.',
            'start_date.required' => 'Tanggal mulai proyek wajib diisi.',
            'contract_value.required' => 'Nilai kontrak wajib diisi.',
        ];
    }
}
