<?php

namespace App\Http\Requests\CRM;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 12 decision #3 — scheduling (or rescheduling) a site survey.
 * Address and Maps link default to the lead's when left empty. Whether
 * it's outside Pekanbaru is set once, when scheduling.
 */
class LeadSurveyRequest extends FormRequest
{
    /** Route-level `role:CEO|MARKETING` middleware gates this (PRD §4.1 "Marketing dan CEO"). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scheduled_at' => ['required', 'date'],
            'address' => ['nullable', 'string', 'max:1000'],
            'maps_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'is_outside_pekanbaru' => [$this->isMethod('put') ? 'prohibited' : 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'scheduled_at.required' => 'Jadwal survey wajib diisi.',
            'scheduled_at.date' => 'Jadwal survey tidak valid.',
            'maps_url.url' => 'Link Google Maps harus berupa URL http/https.',
            'is_outside_pekanbaru.prohibited' => 'Lokasi dalam/luar Pekanbaru tidak bisa diubah — batalkan dan jadwalkan ulang.',
        ];
    }
}
