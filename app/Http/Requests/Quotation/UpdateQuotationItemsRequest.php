<?php

namespace App\Http\Requests\Quotation;

use App\Models\Quotation;
use App\Rules\SelectableUnit;
use Illuminate\Foundation\Http\FormRequest;

class UpdateQuotationItemsRequest extends FormRequest
{
    /** Route-level `role:ESTIMATOR` middleware already gates this action (PRD §7.1 "Quotation" row — EST has CRUD). */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Qty may be fractional (Sprint 11 decision #3). The unit is picked
     * from Master Satuan; a unit SUPERADMIN deactivated after this draft
     * used it stays acceptable for this quotation's own lines.
     */
    public function rules(): array
    {
        /** @var Quotation $quotation */
        $quotation = $this->route('quotation');

        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.qty' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999999'],
            'items.*.unit_id' => [
                'required',
                'integer',
                new SelectableUnit($quotation->items()->pluck('unit_id')->all()),
            ],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Tambahkan minimal satu item RAB.',
            'items.*.description.required' => 'Deskripsi item wajib diisi.',
            'items.*.qty.required' => 'Qty wajib diisi.',
            'items.*.qty.min' => 'Qty minimal 0,01.',
            'items.*.qty.decimal' => 'Qty maksimal 2 angka di belakang koma.',
            'items.*.unit_id.required' => 'Satuan wajib dipilih.',
            'items.*.unit_price.required' => 'Harga satuan wajib diisi.',
        ];
    }
}
