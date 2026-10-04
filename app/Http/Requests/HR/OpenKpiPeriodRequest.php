<?php

namespace App\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;

/** SDM (Sprint 10, §3.3) — open a KPI month (`YYYY-MM`, not in the future — KpiService checks). */
class OpenKpiPeriodRequest extends FormRequest
{
    /** Route-level `role:HR` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'period.required' => 'Periode wajib diisi.',
            'period.regex' => 'Format periode harus YYYY-MM.',
        ];
    }
}
