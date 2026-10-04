<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class PmMaterialRequestDecisionRequest extends FormRequest
{
    /** Route-level `role:PM` + ProjectMaterialPolicy::pmDecide() gate this. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['required_if:decision,reject', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'decision.required' => 'Keputusan wajib dipilih.',
            'decision.in' => 'Keputusan tidak valid.',
            'reason.required_if' => 'Alasan penolakan wajib diisi.',
        ];
    }
}
