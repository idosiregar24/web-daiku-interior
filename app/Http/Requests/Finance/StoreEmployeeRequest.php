<?php

namespace App\Http\Requests\Finance;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    /** Route-level `role:FINANCE` middleware already gates this action (Sprint 9 decision #7). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'position' => ['required', 'string', 'max:100'],
            'base_salary' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            // Optional link to a system account — at most one employee per
            // account. Field staff are paid per task (Upah Tukang), never a
            // monthly salary, so their accounts can't be linked.
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id'),
                Rule::unique('employees', 'user_id')->ignore($this->route('employee')),
                function (string $attribute, mixed $value, Closure $fail) {
                    if (User::find($value)?->hasRole('FIELD_STAFF')) {
                        $fail('Tukang dibayar lewat Upah Tukang per task, bukan gaji bulanan — akunnya tidak bisa ditautkan.');
                    }
                },
            ],
            'bank_name' => ['nullable', 'string', 'max:50'],
            'account_no' => ['nullable', 'string', 'max:50'],
            'join_date' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama karyawan wajib diisi.',
            'name.max' => 'Nama karyawan maksimal 100 karakter.',
            'position.required' => 'Jabatan wajib diisi.',
            'position.max' => 'Jabatan maksimal 100 karakter.',
            'base_salary.required' => 'Gaji pokok wajib diisi.',
            'base_salary.numeric' => 'Gaji pokok harus berupa angka.',
            'base_salary.gt' => 'Gaji pokok harus lebih dari 0.',
            'base_salary.max' => 'Gaji pokok terlalu besar.',
            'user_id.exists' => 'Akun user tidak ditemukan.',
            'user_id.unique' => 'Akun ini sudah ditautkan ke karyawan lain.',
            'bank_name.max' => 'Nama bank maksimal 50 karakter.',
            'account_no.max' => 'Nomor rekening maksimal 50 karakter.',
            'join_date.date' => 'Tanggal bergabung tidak valid.',
            'join_date.before_or_equal' => 'Tanggal bergabung tidak boleh di masa depan.',
            'notes.max' => 'Catatan maksimal 1000 karakter.',
        ];
    }
}
