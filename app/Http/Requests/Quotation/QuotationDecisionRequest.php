<?php

namespace App\Http\Requests\Quotation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuotationDecisionRequest extends FormRequest
{
    /** Route-level `role:CEO|PM` middleware already gates who can reach these actions; QuotationService decides which of the two the actor's role lets them use. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'decision.required' => 'Keputusan wajib dipilih.',
        ];
    }
}
