<?php

namespace App\Http\Requests\CompanyProfile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 20 Sub 05 — a testimonial (create and edit). Text only; the
 * client is a label such as "Ibu R., Kitchen Set — Panam".
 */
class SaveTestimonialRequest extends FormRequest
{
    /** Route-level `role:CEO|MARKETING` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_label' => ['required', 'string', 'max:120'],
            'quote' => ['required', 'string', 'max:600'],
            'portfolio_item_id' => ['nullable', 'integer', 'exists:portfolio_items,id'],
            'is_published' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function messages(): array
    {
        return [
            'client_label.required' => 'Nama klien wajib diisi.',
            'client_label.max' => 'Nama klien maksimal 120 karakter.',
            'quote.required' => 'Isi testimoni wajib diisi.',
            'quote.max' => 'Isi testimoni maksimal 600 karakter.',
            'portfolio_item_id.exists' => 'Portofolio tidak ditemukan.',
        ];
    }
}
