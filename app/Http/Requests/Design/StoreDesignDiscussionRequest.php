<?php

namespace App\Http\Requests\Design;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 12 D6 — a message in the Arsitek ↔ Estimator thread. Who may
 * write is DesignPolicy::discuss(); that the RAB belongs to the same lead
 * is checked in DesignService::discuss().
 */
class StoreDesignDiscussionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            'attachment_url' => ['nullable', 'url:http,https', 'max:2048'],
            'quotation_id' => ['nullable', 'integer', 'exists:quotations,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'Pesan wajib diisi.',
            'body.max' => 'Pesan maksimal 5000 karakter.',
            'attachment_url.url' => 'Lampiran harus berupa link http/https yang valid.',
            'attachment_url.max' => 'Link lampiran terlalu panjang.',
        ];
    }
}
