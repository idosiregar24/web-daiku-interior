<?php

namespace App\Http\Requests\CRM;

use App\Http\Requests\Concerns\ValidatesRabReferences;
use App\Services\LeadService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sprint 12 decision #5 — "Ajukan Desain/Survey". DESAIN moves the lead
 * on; SURVEY also schedules a survey (same fields as LeadSurveyRequest);
 * RAB_SURVEY / RAB_DESAIN / RAB_PROYEK ask the Estimator for that RAB
 * (decision #7 — the note is what the Estimator works from, so required).
 */
class SubmitLeadRequestRequest extends FormRequest
{
    use ValidatesRabReferences;

    /** Route-level `role:CEO|MARKETING` middleware gates this. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $survey = Rule::requiredIf(fn () => $this->input('type') === 'SURVEY');

        return [
            'type' => ['required', Rule::in(['SURVEY', ...array_keys(LeadService::RAB_REQUEST_TYPES)])],
            'note' => [Rule::requiredIf(fn () => array_key_exists((string) $this->input('type'), LeadService::RAB_REQUEST_TYPES)), 'nullable', 'string', 'max:2000'],
            // Sprint 14 Sub 02 — "Lainnya": the RAB's own name.
            'custom_name' => [Rule::requiredIf(fn () => $this->input('type') === 'RAB_LAINNYA'), 'nullable', 'string', 'min:3', 'max:100'],
            // Sprint 14 Sub 01 — references for the Estimator (RAB requests only).
            ...$this->referenceRules(),
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
            'note.required' => 'Catatan untuk Estimator wajib diisi.',
            'scheduled_at.required' => 'Jadwal survey wajib diisi.',
            'maps_url.url' => 'Link Google Maps harus berupa URL http/https.',
            'custom_name.required' => 'Isi nama RAB-nya, mis. "Renovasi Pagar".',
            'custom_name.min' => 'Nama RAB minimal 3 karakter.',
            'custom_name.max' => 'Nama RAB maksimal 100 karakter.',
            ...$this->referenceMessages(),
        ];
    }
}
