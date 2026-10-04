<?php

namespace App\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;

/** SDM (Sprint 10, §3.3) — HR fills (or clears) a MANUAL KPI actual of an OPEN month. */
class UpdateKpiScoreRequest extends FormRequest
{
    /** Route-level `role:HR` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'actual' => ['present', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'actual.present' => 'Nilai aktual wajib dikirim.',
            'actual.numeric' => 'Nilai aktual harus berupa angka.',
            'actual.min' => 'Nilai aktual tidak boleh negatif.',
            'actual.max' => 'Nilai aktual terlalu besar.',
        ];
    }
}
