<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 23 K14 — the PM's "Buka Proyek" form. Route `role:PM` +
 * ProjectOpeningPolicy::open() (the PM who approved the RAB) gate it.
 * Mirrored by OpenProjectDialog's Zod schema; the people rules are shared
 * with Edit Proyek (ValidatesProjectPeople).
 */
class OpenProjectRequest extends FormRequest
{
    use ValidatesProjectPeople;

    /** Before validation, so another PM gets a 403 — not field errors. */
    public function authorize(): bool
    {
        return $this->user()->can('open', $this->route('opening'));
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'pm_id' => ['required', 'integer', $this->holdsRole('PM', 'PM yang dipilih bukan Project Manager aktif.')],
            // Sprint 12 D2 — optional; the PM may change it later.
            'assistant_pm_id' => ['nullable', 'integer', $this->holdsRole('ASISTEN_PM', 'Asisten PM yang dipilih tidak valid.')],
            ...$this->teamRules(),
            'opened_on' => ['required', 'date'],
            // Sprint 23 Q10 (assumption in the plan): work never starts before the project is opened.
            'start_date' => ['required', 'date', 'after_or_equal:opened_on'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama proyek wajib diisi.',
            'name.max' => 'Nama proyek maksimal 255 karakter.',
            'pm_id.required' => 'Project Manager wajib dipilih.',
            ...$this->teamMessages(),
            'opened_on.required' => 'Tanggal buka proyek wajib diisi.',
            'opened_on.date' => 'Tanggal buka proyek tidak valid.',
            'start_date.required' => 'Tanggal mulai wajib diisi.',
            'start_date.date' => 'Tanggal mulai tidak valid.',
            'start_date.after_or_equal' => 'Tanggal mulai tidak boleh sebelum tanggal buka proyek.',
            'end_date.date' => 'Tanggal selesai tidak valid.',
            'end_date.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
        ];
    }
}
