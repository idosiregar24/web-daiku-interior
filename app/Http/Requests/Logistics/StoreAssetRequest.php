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
        ];
    }
}
