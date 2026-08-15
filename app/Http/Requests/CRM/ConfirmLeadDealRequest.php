<?php

namespace App\Http\Requests\CRM;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmLeadDealRequest extends FormRequest
{
    /** Route-level `role:CEO|MARKETING` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'pm_id' => ['required', 'exists:users,id'],
            'start_date' => ['required', 'date'],
            'contract_value' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama proyek wajib diisi.',
            'pm_id.required' => 'Project Manager wajib dipilih.',
            'pm_id.exists' => 'PM yang dipilih tidak ditemukan.',
            'start_date.required' => 'Tanggal mulai proyek wajib diisi.',
            'contract_value.required' => 'Nilai kontrak wajib diisi.',
        ];
    }
}
