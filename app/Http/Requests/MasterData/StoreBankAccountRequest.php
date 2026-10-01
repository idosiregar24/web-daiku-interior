<?php

namespace App\Http\Requests\MasterData;

use Illuminate\Foundation\Http\FormRequest;

class StoreBankAccountRequest extends FormRequest
{
    /** Route-level `role:SUPERADMIN` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bank_name' => ['required', 'string', 'max:50'],
            'account_no' => ['required', 'string', 'max:30'],
            'label' => ['required', 'string', 'max:50', 'unique:bank_accounts,label'],
            'opening_balance' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'bank_name.required' => 'Nama bank wajib diisi.',
            'account_no.required' => 'Nomor rekening wajib diisi.',
            'label.required' => 'Label rekening wajib diisi.',
            'label.unique' => 'Label rekening ini sudah dipakai.',
            'opening_balance.numeric' => 'Saldo awal harus berupa angka.',
            'opening_balance.min' => 'Saldo awal tidak boleh negatif.',
            'opening_balance.max' => 'Saldo awal terlalu besar.',
        ];
    }
}
