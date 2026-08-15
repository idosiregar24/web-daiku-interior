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
            'source' => ['required', 'string', 'max:100'],
            'priority' => ['required', Rule::in(['HOT', 'WARM', 'COLD'])],
            'category' => ['nullable', Rule::in(['RESIDENTIAL', 'KOMERSIAL', 'DEVELOPER', 'KONTRAKTOR', 'LAINNYA'])],
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
            'source.required' => 'Sumber lead wajib diisi.',
            'priority.required' => 'Prioritas wajib dipilih.',
            'assigned_to.required' => 'Lead harus di-assign ke salah satu staf Marketing.',
            'assigned_to.exists' => 'Staf yang dipilih tidak ditemukan.',
        ];
    }
}
