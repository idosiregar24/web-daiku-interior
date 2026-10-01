<?php

namespace App\Http\Requests\Finance;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaySalaryRequest extends FormRequest
{
    /**
     * Route-level `role:FINANCE` middleware already gates this action
     * (salaries: CEO read, Finance manage — Sprint 9 decision #7). "One
     * salary per employee per month" and "net pay ≥ 0" are checked in
     * PayrollService under a row lock; the net amount is computed there.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('is_active', true)],
            'period' => [
                'required',
                'string',
                'date_format:Y-m',
                function (string $attribute, mixed $value, Closure $fail) {
                    // Only for a well-formed period — date_format reports the rest.
                    if (is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1 && $value > now()->format('Y-m')) {
                        $fail('Gaji bulan yang belum berjalan belum bisa dibayarkan.');
                    }
                },
            ],
            'allowance' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'deduction' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            // PRD §4.7 "Setiap transaksi wajib mencantumkan rekening bank".
            'bank_account_id' => ['required', 'integer', Rule::exists('bank_accounts', 'id')->where('is_active', true)],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'employee_id.required' => 'Karyawan wajib dipilih.',
            'employee_id.exists' => 'Karyawan tidak ditemukan atau sudah nonaktif.',
            'period.required' => 'Periode gaji wajib diisi.',
            'period.date_format' => 'Periode gaji harus berformat TTTT-BB.',
            'allowance.numeric' => 'Tunjangan harus berupa angka.',
            'allowance.min' => 'Tunjangan tidak boleh negatif.',
            'allowance.max' => 'Tunjangan terlalu besar.',
            'deduction.numeric' => 'Potongan harus berupa angka.',
            'deduction.min' => 'Potongan tidak boleh negatif.',
            'deduction.max' => 'Potongan terlalu besar.',
            'paid_at.required' => 'Tanggal bayar wajib diisi.',
            'paid_at.date' => 'Tanggal bayar tidak valid.',
            'paid_at.before_or_equal' => 'Tanggal bayar tidak boleh di masa depan.',
            'bank_account_id.required' => 'Rekening sumber wajib dipilih.',
            'bank_account_id.integer' => 'Rekening bank tidak valid.',
            'bank_account_id.exists' => 'Rekening bank tidak ditemukan atau tidak aktif.',
            'note.max' => 'Catatan maksimal 255 karakter.',
        ];
    }
}
