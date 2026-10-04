<?php

namespace App\Http\Requests\HR;

use App\Models\Position;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SDM (Sprint 10): HR adds a permanent employee. The job title is picked
 * from the Divisi → Jabatan master (decision #10), and field-staff
 * accounts can never be linked (decision #11).
 */
class StoreEmployeeRequest extends FormRequest
{
    /** Route-level `role:HR` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'position_id' => [
                'required',
                'integer',
                Rule::exists('positions', 'id'),
                function (string $attribute, mixed $value, Closure $fail) {
                    $position = Position::with('division')->find($value);

                    // An inactive position may stay on an employee who already
                    // holds it, but can't be newly assigned.
                    $unchanged = $this->route('employee')?->position_id === (int) $value;

                    if ($position && ! $unchanged && (! $position->is_active || ! $position->division?->is_active)) {
                        $fail('Jabatan ini sudah nonaktif — pilih jabatan lain.');
                    }
                },
            ],
            'base_salary' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            // Optional link to a system account — at most one employee per
            // account. Field staff are paid per task (Upah Tukang), never a
            // monthly salary, and are kept out of SDM (decision #11).
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id'),
                Rule::unique('employees', 'user_id')->ignore($this->route('employee')),
                function (string $attribute, mixed $value, Closure $fail) {
                    if (User::find($value)?->hasRole('FIELD_STAFF')) {
                        $fail('Akun tukang tidak bisa ditautkan ke data karyawan — tukang dibayar lewat Upah Tukang per task.');
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
            'position_id.required' => 'Jabatan wajib dipilih.',
            'position_id.exists' => 'Jabatan tidak ditemukan.',
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
