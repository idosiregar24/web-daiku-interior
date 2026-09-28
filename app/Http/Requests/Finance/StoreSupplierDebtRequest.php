<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

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
            'supplier_name' => ['required', 'string', 'max:100'],
            'total_amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'project_id' => ['nullable', 'exists:projects,id'],
            'due_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_name.required' => 'Nama supplier wajib diisi.',
            'supplier_name.max' => 'Nama supplier maksimal 100 karakter.',
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
