<?php

namespace App\Http\Requests\Analytics;

use Illuminate\Foundation\Http\FormRequest;

class StoreRevenueTargetRequest extends FormRequest
{
    /** Route-level `role:CEO` middleware already gates this action (PRD §7.1 "Analytics – Executive"). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m'],
            'target_amount' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'month.required' => 'Bulan wajib dipilih.',
            'month.date_format' => 'Format bulan tidak valid.',
            'target_amount.required' => 'Nilai target wajib diisi.',
            'target_amount.min' => 'Nilai target tidak boleh negatif.',
        ];
    }
}
