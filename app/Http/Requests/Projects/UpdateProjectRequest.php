<?php

namespace App\Http\Requests\Projects;

use App\Enums\ProjectStatus;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    /**
     * Route-level `role:CEO|PM` plus ProjectPolicy::update() (own project
     * for a PM) in the controller. "Only the CEO may change the PM" and
     * the status/contract-value rules need the stored project, so they
     * live in ProjectService::update().
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'contract_value' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            // COMPLETED is never a manual choice — QaFormService sets it
            // once every milestone passed QA.
            'status' => ['required', Rule::in([
                ProjectStatus::Active->value,
                ProjectStatus::OnHold->value,
                ProjectStatus::Cancelled->value,
            ])],
            'note' => ['nullable', 'string', 'max:1000', 'required_if:status,'.ProjectStatus::Cancelled->value],
            // Sprint 12 D2 — the CEO or the project's PM changes the
            // Asisten PM here; null = none. Omitted = left as it is.
            'assistant_pm_id' => [
                'sometimes',
                'nullable',
                'integer',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! User::role('ASISTEN_PM')->where('is_active', true)->whereKey($value)->exists()) {
                        $fail('Asisten PM harus user aktif dengan role Asisten PM.');
                    }
                },
            ],
            // Optional: a PM's form never sends it. PRD §4.4 "PM di-assign
            // oleh CEO" — enforced in ProjectService::update().
            'pm_id' => [
                'sometimes',
                'required',
                'integer',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! User::role('PM')->where('is_active', true)->whereKey($value)->exists()) {
                        $fail('Project Manager harus user aktif dengan role PM.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama proyek wajib diisi.',
            'name.max' => 'Nama proyek maksimal 255 karakter.',
            'start_date.required' => 'Tanggal mulai proyek wajib diisi.',
            'start_date.date' => 'Tanggal mulai tidak valid.',
            'end_date.date' => 'Tanggal selesai tidak valid.',
            'end_date.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            'contract_value.required' => 'Nilai kontrak wajib diisi.',
            'contract_value.numeric' => 'Nilai kontrak harus berupa angka.',
            'contract_value.min' => 'Nilai kontrak tidak boleh negatif.',
            'contract_value.max' => 'Nilai kontrak terlalu besar.',
            'status.required' => 'Status proyek wajib dipilih.',
            'status.in' => 'Status proyek hanya bisa diubah ke ACTIVE, ON_HOLD, atau CANCELLED — COMPLETED diset otomatis setelah semua milestone lolos QA.',
            'note.required_if' => 'Alasan pembatalan proyek wajib diisi.',
            'note.max' => 'Catatan maksimal 1000 karakter.',
            'pm_id.required' => 'Project Manager wajib dipilih.',
            'pm_id.integer' => 'Project Manager tidak valid.',
        ];
    }
}
