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
     * Sprint 12 decision #11 — the Excel-format RAB: `sections` (bagian
     * pekerjaan) with their items, plus discount and rounding. A flat
     * `items` list without sections is still accepted (pre-Sprint-12
     * callers). Qty may be fractional (Sprint 11 #3); a unit SUPERADMIN
     * deactivated after this draft used it stays acceptable for its lines.
     */
    public function rules(): array
    {
        /** @var Quotation $quotation */
        $quotation = $this->route('quotation');
        $unit = ['required', 'integer', new SelectableUnit($quotation->items()->pluck('unit_id')->all())];

        $item = fn (string $prefix) => [
            "{$prefix}.description" => ['required', 'string', 'max:255'],
            "{$prefix}.dim_length" => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            "{$prefix}.dim_width_height" => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            "{$prefix}.qty" => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999999'],
            "{$prefix}.unit_id" => $unit,
            "{$prefix}.unit_price" => ['required', 'numeric', 'min:0'],
        ];

        return [
            'sections' => ['required_without:items', 'array', 'min:1'],
            'sections.*.name' => ['required', 'string', 'max:150'],
            'sections.*.items' => ['required', 'array', 'min:1'],
            ...$item('sections.*.items.*'),
            'items' => ['required_without:sections', 'array', 'min:1'],
            ...$item('items.*'),
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'rounded_total' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
        ];
    }

    public function messages(): array
    {
        $messages = [
            'sections.required_without' => 'Tambahkan minimal satu bagian pekerjaan.',
            'sections.*.name.required' => 'Nama bagian pekerjaan wajib diisi.',
            'sections.*.items.required' => 'Setiap bagian pekerjaan berisi minimal satu item.',
            'items.required_without' => 'Tambahkan minimal satu item RAB.',
            'discount_amount.min' => 'Diskon tidak boleh negatif.',
            'rounded_total.min' => 'Pembulatan tidak boleh negatif.',
        ];

        foreach (['items.*', 'sections.*.items.*'] as $prefix) {
            $messages += [
                "{$prefix}.description.required" => 'Nama item wajib diisi.',
                "{$prefix}.qty.required" => 'Volume wajib diisi.',
                "{$prefix}.qty.min" => 'Volume minimal 0,01.',
                "{$prefix}.qty.decimal" => 'Volume maksimal 2 angka di belakang koma.',
                "{$prefix}.unit_id.required" => 'Satuan wajib dipilih.',
                "{$prefix}.unit_price.required" => 'Harga satuan wajib diisi.',
            ];
        }

        return $messages;
    }
}
