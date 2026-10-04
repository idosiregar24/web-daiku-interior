<?php

namespace App\Http\Requests\Logistics;

use App\Rules\SelectableUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaterialRequestRequest extends FormRequest
{
    /** Route-level role middleware + ProjectMaterialPolicy::request() (in the controller) gate this. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Decision #13's required data for PM/Estimator: name, spec, unit,
     * qty, estimated price and why the catalog doesn't do (vendor and
     * photo link optional). A Tukang only names the item, qty and a note —
     * Logistics completes the rest while reviewing (Sprint 12 #31).
     */
    public function rules(): array
    {
        $tukang = $this->isTukang();

        return [
            'name' => ['required', 'string', 'max:150'],
            'qty' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:1000000'],
            'spec' => [$tukang ? 'nullable' : 'required', 'string', 'max:255'],
            'unit_id' => [$tukang ? 'nullable' : 'required', 'integer', new SelectableUnit],
            'estimated_price' => [$tukang ? 'nullable' : 'required', 'numeric', 'min:0', 'max:9999999999'],
            'reason' => [$tukang ? 'nullable' : 'required', 'string', 'max:1000'],
            'vendor_id' => [$tukang ? 'prohibited' : 'nullable', 'integer', Rule::exists('vendors', 'id')->where('is_active', true)],
            'photo_link' => [$tukang ? 'prohibited' : 'nullable', 'url', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama barang wajib diisi.',
            'qty.required' => 'Jumlah wajib diisi.',
            'qty.min' => 'Jumlah minimal 0,01.',
            'qty.decimal' => 'Jumlah maksimal 2 angka di belakang koma.',
            'spec.required' => 'Spesifikasi wajib diisi.',
            'unit_id.required' => 'Satuan wajib dipilih.',
            'estimated_price.required' => 'Estimasi harga wajib diisi.',
            'reason.required' => 'Alasan tidak memakai barang katalog wajib diisi.',
            'vendor_id.exists' => 'Vendor tidak ditemukan atau sudah nonaktif.',
            'photo_link.url' => 'Link foto harus berupa URL yang valid.',
        ];
    }

    private function isTukang(): bool
    {
        $user = $this->user();

        return $user->hasRole('FIELD_STAFF') && ! $user->hasAnyRole(['PM', 'ESTIMATOR', 'SUPERADMIN']);
    }
}
