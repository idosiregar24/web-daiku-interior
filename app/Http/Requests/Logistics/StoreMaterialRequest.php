<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class StoreMaterialRequest extends FormRequest
{
    /** Route-level `role:LOGISTICS` middleware already gates this action (PRD §7.1 "Material – Master": LOG CRUD). */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `stock` is deliberately absent — it only changes through stock
     * in/out (StockService), so every unit has a ledger row behind it.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'unit' => ['required', 'string', 'max:20'],
            'category' => ['nullable', 'string', 'max:50'],
            'cost_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'sell_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'min_stock' => ['required', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama material wajib diisi.',
            'unit.required' => 'Satuan wajib diisi.',
            'cost_price.required' => 'Harga modal wajib diisi.',
            'sell_price.required' => 'Harga jual wajib diisi.',
            'min_stock.required' => 'Stok minimum wajib diisi.',
            'min_stock.integer' => 'Stok minimum harus bilangan bulat.',
        ];
    }
}
