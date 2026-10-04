<?php

namespace App\Http\Requests\CRM;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sprint 12 decision #5 — "Ajukan Desain/Survey". DESAIN moves the lead
 * on; SURVEY also schedules a survey (same fields as LeadSurveyRequest).
 * The RAB requests (Jasa Survey / Jasa Desain / Proyek) come in Sub 3.
 */
class SubmitLeadRequestRequest extends FormRequest
{
    /** Route-level `role:CEO|MARKETING` middleware gates this. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $survey = Rule::requiredIf(fn () => $this->input('type') === 'SURVEY');

        return [
            'type' => ['required', Rule::in(['DESAIN', 'SURVEY'])],
            'note' => ['nullable', 'string', 'max:1000'],
            'scheduled_at' => [$survey, 'nullable', 'date'],
            'address' => ['nullable', 'string', 'max:1000'],
            'maps_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'is_outside_pekanbaru' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Pilih jenis pengajuan.',
            'type.in' => 'Jenis pengajuan tidak valid.',
            'scheduled_at.required' => 'Jadwal survey wajib diisi.',
            'maps_url.url' => 'Link Google Maps harus berupa URL http/https.',
        ];
    }
}
