<?php

namespace App\Http\Requests\HR;

use App\Enums\DisciplinaryType;
use App\Services\DisciplineService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SDM (Sprint 10, §3.1): HR records a reprimand or warning letter. The SP
 * escalation rule (decision #12) needs the employee's history, so it's
 * enforced in DisciplineService::issue(), not here.
 */
class StoreDisciplinaryRecordRequest extends FormRequest
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
            'type' => ['required', Rule::in(array_map(fn (DisciplinaryType $type) => $type->value, DisciplineService::ISSUABLE_TYPES))],
            'issued_on' => ['required', 'date', 'before_or_equal:today'],
            'valid_until' => ['nullable', 'date', 'after:issued_on'],
            'description' => ['required', 'string', 'max:2000'],
            'link' => ['nullable', 'url:http,https', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'employee_id.required' => 'Karyawan wajib dipilih.',
            'employee_id.exists' => 'Karyawan tidak ditemukan.',
            'type.required' => 'Jenis catatan wajib dipilih.',
            'type.in' => 'Jenis catatan tidak valid.',
            'issued_on.required' => 'Tanggal terbit wajib diisi.',
            'issued_on.date' => 'Tanggal terbit tidak valid.',
            'issued_on.before_or_equal' => 'Tanggal terbit tidak boleh di masa depan.',
            'valid_until.date' => 'Masa berlaku tidak valid.',
            'valid_until.after' => 'Masa berlaku harus setelah tanggal terbit.',
            'description.required' => 'Uraian wajib diisi.',
            'description.max' => 'Uraian maksimal 2000 karakter.',
            'link.url' => 'Link dokumen harus berupa URL http/https yang valid.',
            'link.max' => 'Link dokumen maksimal 500 karakter.',
        ];
    }
}
