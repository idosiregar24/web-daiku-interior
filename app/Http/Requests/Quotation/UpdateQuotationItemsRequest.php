<?php

namespace App\Http\Requests\Quotation;

use Illuminate\Foundation\Http\FormRequest;

class UpdateQuotationItemsRequest extends FormRequest
{
    /** Route-level `role:ESTIMATOR` middleware already gates this action (PRD §7.1 "Quotation" row — EST has CRUD). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.unit' => ['required', 'string', 'max:20'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Tambahkan minimal satu item RAB.',
            'items.*.description.required' => 'Deskripsi item wajib diisi.',
            'items.*.qty.required' => 'Qty wajib diisi.',
            'items.*.unit.required' => 'Satuan wajib diisi.',
            'items.*.unit_price.required' => 'Harga satuan wajib diisi.',
        ];
    }
}
