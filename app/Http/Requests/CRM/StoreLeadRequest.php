<?php

namespace App\Http\Requests\CRM;

use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreLeadRequest extends FormRequest
{
    /**
     * Route-level `role:CEO|MARKETING` middleware already gates who can
     * reach this action (PRD §4.1 "Hanya Marketing/Sales dan CEO yang bisa
     * membuat/edit lead") — no per-resource ownership check applies to
     * Lead, so no Policy is needed on top of that.
     */
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
            'priority' => ['required', new Enum(LeadPriority::class)],
            'category' => ['nullable', Rule::in(['RESIDENTIAL', 'KOMERSIAL', 'DEVELOPER', 'KONTRAKTOR', 'LAINNYA'])],
            'service' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', 'string', 'max:50'],
            'order_detail' => ['nullable', 'string'],
            'status' => ['sometimes', new Enum(LeadStatus::class)],
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
