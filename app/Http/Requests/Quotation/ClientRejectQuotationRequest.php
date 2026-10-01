<?php

namespace App\Http\Requests\Quotation;

use Illuminate\Foundation\Http\FormRequest;

class ClientRejectQuotationRequest extends FormRequest
{
    /** Route-level `role:CEO|MARKETING` middleware already gates this action (the same actors as "Konfirmasi Deal"). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.required' => 'Alasan penolakan klien wajib diisi.',
            'note.max' => 'Alasan penolakan maksimal 1000 karakter.',
        ];
    }
}
