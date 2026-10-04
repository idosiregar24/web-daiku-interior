<?php

namespace App\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SDM (Sprint 10, §3.4) — HR opens a review for one employee/semester
 * (1 = Jan–Jun, 2 = Jul–Des). Eligibility, "semester already started" and
 * uniqueness are re-checked in PerformanceReviewService::create().
 */
class StorePerformanceReviewRequest extends FormRequest
{
    /** Route-level `role:HR` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'year' => ['required', 'integer', 'min:2020', 'max:'.now()->year],
            'semester' => ['required', 'integer', 'in:1,2'],
        ];
    }

    public function messages(): array
    {
        return [
            'employee_id.required' => 'Karyawan wajib dipilih.',
            'employee_id.integer' => 'Karyawan tidak valid.',
            'employee_id.exists' => 'Karyawan tidak ditemukan.',
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
