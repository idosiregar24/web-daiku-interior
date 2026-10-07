<?php

namespace App\Http\Requests\CRM;

use App\Http\Requests\Concerns\ValidatesLeadContact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeadRequest extends FormRequest
{
    use ValidatesLeadContact;

    protected function prepareForValidation(): void
    {
        $this->prepareLeadContact();
    }

    /** Route-level `role:CEO|MARKETING` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_name' => ['required', 'string', 'max:255'],
            ...$this->leadContactRules(),
            'lead_source_id' => ['required', 'integer', 'exists:lead_sources,id'],
            'priority' => ['required', Rule::in(['HOT', 'WARM', 'COLD'])],
            'lead_category_id' => ['nullable', 'integer', 'exists:lead_categories,id'],
            'service' => ['nullable', 'string', 'max:255'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'gender' => ['nullable', 'string', 'max:50'],
            'order_detail' => ['nullable', 'string'],
            'assigned_to' => ['required', 'exists:users,id'],
            // Follow-ups (FU-n) are managed on the lead's timeline since Sprint 12.
            'first_contacted_at' => ['nullable', 'date', 'before_or_equal:today'],
            'address' => ['nullable', 'string', 'max:1000'],
            'maps_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'client_name.required' => 'Nama klien wajib diisi.',
            ...$this->leadContactMessages(),
            'lead_source_id.required' => 'Sumber lead wajib dipilih.',
            'lead_source_id.integer' => 'Sumber lead tidak valid.',
            'lead_source_id.exists' => 'Sumber lead yang dipilih tidak ditemukan di Data Master.',
            'lead_category_id.integer' => 'Kategori tidak valid.',
            'lead_category_id.exists' => 'Kategori yang dipilih tidak ditemukan di Data Master.',
            'city_id.exists' => 'Kota yang dipilih tidak ditemukan di Data Master.',
            'priority.required' => 'Prioritas wajib dipilih.',
            'assigned_to.required' => 'Lead harus di-assign ke salah satu staf Marketing.',
            'assigned_to.exists' => 'Staf yang dipilih tidak ditemukan.',
            'first_contacted_at.before_or_equal' => 'Tanggal pertama dihubungi tidak boleh di masa depan.',
            'maps_url.url' => 'Link Google Maps harus berupa URL http/https.',
        ];
    }
}
