<?php

namespace App\Http\Requests\Finance;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffLoanRequest extends FormRequest
{
    /** Route-level `role:FINANCE` middleware already gates this action (PRD §7.1 "Finance – Transaction"). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'staff_id' => [
                'required',
                'integer',
                'exists:users,id',
                function (string $attribute, mixed $value, Closure $fail) {
                    $staff = User::find($value);

                    if ($staff && ! $staff->hasRole('FIELD_STAFF')) {
                        $fail('Pinjaman hanya bisa diberikan kepada tukang (Field Staff).');
                    } elseif ($staff && ! $staff->is_active) {
                        $fail('Tukang ini sudah nonaktif — pinjaman baru tidak bisa diberikan.');
                    }
                },
            ],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'installment_amount' => ['required', 'numeric', 'gt:0', 'lte:amount'],
            // PRD §4.7 "Setiap transaksi wajib mencantumkan rekening bank".
            'bank_account_id' => [
                'required',
                'integer',
                Rule::exists('bank_accounts', 'id')->where('is_active', true),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'staff_id.required' => 'Tukang wajib dipilih.',
            'staff_id.exists' => 'Tukang tidak ditemukan.',
            'amount.required' => 'Nominal pinjaman wajib diisi.',
            'amount.numeric' => 'Nominal pinjaman harus berupa angka.',
            'amount.gt' => 'Nominal pinjaman harus lebih dari 0.',
            'amount.max' => 'Nominal pinjaman terlalu besar.',
            'installment_amount.required' => 'Nominal cicilan wajib diisi.',
            'installment_amount.numeric' => 'Nominal cicilan harus berupa angka.',
            'installment_amount.gt' => 'Nominal cicilan harus lebih dari 0.',
            'installment_amount.lte' => 'Nominal cicilan tidak boleh melebihi nominal pinjaman.',
            'bank_account_id.required' => 'Rekening bank wajib dipilih.',
            'bank_account_id.exists' => 'Rekening bank tidak ditemukan atau tidak aktif.',
            'description.max' => 'Keterangan maksimal 1000 karakter.',
        ];
    }
}
