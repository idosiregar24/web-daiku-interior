<?php

namespace App\Http\Requests\CRM;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeadStatusRequest extends FormRequest
{
    /** Route-level `role:CEO|MARKETING` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // CLOSING excluded on purpose — only reachable via the
            // confirmDeal endpoint (LeadService::changeStatus() rejects it
            // too, this just gives a client-side-friendly message sooner).
            'status' => ['required', Rule::in(['FOLLOW_UP', 'DEAL_DESAIN', 'LOST'])],
            'lost_reason' => ['required_if:status,LOST', 'nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Status wajib dipilih.',
            'lost_reason.required_if' => 'Alasan lost wajib diisi.',
        ];
    }
}
