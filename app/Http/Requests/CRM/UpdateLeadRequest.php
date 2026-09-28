<?php

namespace App\Http\Requests\CRM;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeadRequest extends FormRequest
{
    /** Route-level `role:CEO|MARKETING` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_name' => ['required', 'string', 'max:255'],
            'contact' => ['required', 'string', 'max:255'],
            'lead_source_id' => ['required', 'integer', 'exists:lead_sources,id'],
            'priority' => ['required', Rule::in(['HOT', 'WARM', 'COLD'])],
            'lead_category_id' => ['nullable', 'integer', 'exists:lead_categories,id'],
            'service' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', 'string', 'max:50'],
            'order_detail' => ['nullable', 'string'],
            'assigned_to' => ['required', 'exists:users,id'],
            'follow_up_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'client_name.required' => 'Nama klien wajib diisi.',
            'contact.required' => 'Kontak (telepon/email) wajib diisi.',
            'lead_source_id.required' => 'Sumber lead wajib dipilih.',
            'lead_source_id.integer' => 'Sumber lead tidak valid.',
            'lead_source_id.exists' => 'Sumber lead yang dipilih tidak ditemukan di Data Master.',
            'lead_category_id.integer' => 'Kategori tidak valid.',
            'lead_category_id.exists' => 'Kategori yang dipilih tidak ditemukan di Data Master.',
            'priority.required' => 'Prioritas wajib dipilih.',
            'assigned_to.required' => 'Lead harus di-assign ke salah satu staf Marketing.',
            'assigned_to.exists' => 'Staf yang dipilih tidak ditemukan.',
        ];
    }
}
