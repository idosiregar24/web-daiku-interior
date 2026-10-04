<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierDebtRequest extends FormRequest
{
    /** Route-level `role:FINANCE` middleware already gates this action (PRD §7.1 "Finance – Transaction"). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Master Vendor (Sprint 11 Sub 2) — active vendors only; the CEO adds new ones.
            'vendor_id' => ['required', 'integer', Rule::exists('vendors', 'id')->where('is_active', true)],
            'total_amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'project_id' => ['nullable', 'exists:projects,id'],
            'due_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'vendor_id.required' => 'Vendor wajib dipilih.',
            'vendor_id.exists' => 'Vendor belum terdaftar atau sudah nonaktif — minta CEO menambahkannya di Data Master → Vendor.',
            'total_amount.required' => 'Total hutang wajib diisi.',
            'total_amount.numeric' => 'Total hutang harus berupa angka.',
            'total_amount.gt' => 'Total hutang harus lebih dari 0.',
            'total_amount.max' => 'Total hutang terlalu besar.',
            'project_id.exists' => 'Proyek tidak ditemukan.',
            'due_date.date' => 'Tanggal jatuh tempo tidak valid.',
            'description.max' => 'Keterangan maksimal 2000 karakter.',
        ];
    }
}
