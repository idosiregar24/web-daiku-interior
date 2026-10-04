<?php

namespace App\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;

/** SDM (Sprint 10, §3.4) — "Buat untuk semua karyawan" for one semester (1 = Jan–Jun, 2 = Jul–Des). */
class BulkPerformanceReviewRequest extends FormRequest
{
    /** Route-level `role:HR` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2020', 'max:'.now()->year],
            'semester' => ['required', 'integer', 'in:1,2'],
        ];
    }

    public function messages(): array
    {
        return [
            'year.required' => 'Tahun wajib diisi.',
            'year.integer' => 'Tahun tidak valid.',
            'year.min' => 'Tahun minimal 2020.',
            'year.max' => 'Tahun tidak boleh di masa depan.',
            'semester.required' => 'Semester wajib dipilih.',
            'semester.integer' => 'Semester harus 1 atau 2.',
            'semester.in' => 'Semester harus 1 atau 2.',
        ];
    }
}
