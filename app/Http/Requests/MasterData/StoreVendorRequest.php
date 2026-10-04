<?php

namespace App\Http\Requests\MasterData;

use App\Models\Vendor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVendorRequest extends FormRequest
{
    /** Route-level `role:CEO` middleware already gates this action (SUPERADMIN passes via god-mode). */
    public function authorize(): bool
    {
        return true;
    }

    /** Collapse whitespace before the unique check, the same way VendorService stores the name. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim(preg_replace('/\s+/u', ' ', $this->input('name')) ?? '')]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('vendors', 'name')->ignore($this->route('vendor'))],
            'type' => ['required', Rule::in(Vendor::TYPES)],
            'contact' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:1000'],
            'bank_name' => ['nullable', 'string', 'max:50'],
            'bank_account_number' => ['nullable', 'string', 'max:50'],
            'account_holder' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama vendor wajib diisi.',
            'name.max' => 'Nama vendor maksimal 100 karakter.',
            'name.unique' => 'Vendor dengan nama ini sudah ada.',
            'type.required' => 'Jenis vendor wajib dipilih.',
            'type.in' => 'Jenis vendor tidak valid.',
            'contact.max' => 'Kontak maksimal 100 karakter.',
            'address.max' => 'Alamat maksimal 1000 karakter.',
            'bank_name.max' => 'Nama bank maksimal 50 karakter.',
            'bank_account_number.max' => 'Nomor rekening maksimal 50 karakter.',
            'account_holder.max' => 'Nama pemilik rekening maksimal 100 karakter.',
        ];
    }
}
