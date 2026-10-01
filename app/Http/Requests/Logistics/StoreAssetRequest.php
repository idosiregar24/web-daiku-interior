<?php

namespace App\Http\Requests\Logistics;

use App\Enums\AssetCondition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetRequest extends FormRequest
{
    /** Route-level `role:LOGISTICS` middleware already gates this action (PRD §7.1 "Asset Inventory": LOG CRUD). */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The installment plan (PRD §4.7 "Aset & Cicilan") is optional; its
     * fields are dropped entirely when `has_installment` is off. Rules that
     * depend on payments already made (total ≥ terbayar, plan can't be
     * switched off once paid) live in AssetInstallmentService — they need
     * the row lock.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', 'string', 'max:50'],
            'purchase_date' => ['required', 'date', 'before_or_equal:today'],
            'value' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'condition' => ['required', Rule::enum(AssetCondition::class)],
            'location' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'has_installment' => ['sometimes', 'boolean'],
            'total_install' => ['exclude_unless:has_installment,true', 'required', 'numeric', 'gt:0', 'max:9999999999999'],
            'installment_amount' => ['exclude_unless:has_installment,true', 'nullable', 'numeric', 'gt:0', 'lte:total_install'],
            'installment_due_day' => ['exclude_unless:has_installment,true', 'nullable', 'integer', 'between:1,28'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama aset wajib diisi.',
            'category.required' => 'Kategori wajib diisi.',
            'purchase_date.required' => 'Tanggal pembelian wajib diisi.',
            'purchase_date.before_or_equal' => 'Tanggal pembelian tidak boleh di masa depan.',
            'value.required' => 'Nilai aset wajib diisi.',
            'condition.required' => 'Kondisi aset wajib dipilih.',
            'has_installment.boolean' => 'Pilihan cicilan tidak valid.',
            'total_install.required' => 'Total cicilan wajib diisi untuk aset bercicilan.',
            'total_install.numeric' => 'Total cicilan harus berupa angka.',
            'total_install.gt' => 'Total cicilan harus lebih dari 0.',
            'total_install.max' => 'Total cicilan terlalu besar.',
            'installment_amount.numeric' => 'Cicilan per bulan harus berupa angka.',
            'installment_amount.gt' => 'Cicilan per bulan harus lebih dari 0.',
            'installment_amount.lte' => 'Cicilan per bulan tidak boleh melebihi total cicilan.',
            'installment_due_day.integer' => 'Tanggal jatuh tempo harus berupa angka.',
            'installment_due_day.between' => 'Tanggal jatuh tempo harus antara 1 dan 28.',
        ];
    }
}
