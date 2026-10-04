<?php

namespace App\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SDM (Sprint 10, decision #3): HR requests a base-salary change for the
 * CEO to decide. "Must differ from the current salary", "one PENDING per
 * employee" and "the review belongs to this employee" are enforced in
 * SalaryChangeService::request() under the employee row lock.
 */
class StoreSalaryChangeRequest extends FormRequest
{
    /** Route-level `role:HR` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')],
            'new_salary' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'effective_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
            'performance_review_id' => ['nullable', 'integer', Rule::exists('performance_reviews', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'employee_id.required' => 'Karyawan wajib dipilih.',
            'employee_id.exists' => 'Karyawan tidak ditemukan.',
            'new_salary.required' => 'Gaji pokok baru wajib diisi.',
            'new_salary.numeric' => 'Gaji pokok baru harus berupa angka.',
            'new_salary.gt' => 'Gaji pokok baru harus lebih dari 0.',
            'new_salary.max' => 'Gaji pokok baru terlalu besar.',
            'effective_date.required' => 'Tanggal berlaku wajib diisi.',
            'effective_date.date' => 'Tanggal berlaku tidak valid.',
            'reason.required' => 'Alasan perubahan wajib diisi.',
            'reason.max' => 'Alasan maksimal 2000 karakter.',
            'performance_review_id.exists' => 'Evaluasi yang dirujuk tidak ditemukan.',
        ];
    }
}
