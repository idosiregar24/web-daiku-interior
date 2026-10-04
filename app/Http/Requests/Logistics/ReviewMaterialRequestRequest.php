<?php

namespace App\Http\Requests\Logistics;

use App\Enums\ProjectMaterialSource;
use App\Models\ProjectMaterial;
use App\Rules\SelectableUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewMaterialRequestRequest extends FormRequest
{
    /** Route-level `role:LOGISTICS` + ProjectMaterialPolicy::review() gate this. */
    public function authorize(): bool
    {
        return true;
    }

    /** Fields follow Logistics' decision (decision #13); see MaterialRequestService::review(). */
    public function rules(): array
    {
        $approve = Rule::requiredIf(fn () => $this->input('decision') !== ProjectMaterial::DECISION_TOLAK);
        $newItem = 'required_if:decision,'.ProjectMaterial::DECISION_DAFTAR_KATALOG.','.ProjectMaterial::DECISION_CUSTOM;

        return [
            'decision' => ['required', Rule::in(ProjectMaterial::DECISIONS)],
            'qty' => [$approve, 'nullable', 'numeric', 'decimal:0,2', 'min:0.01', 'max:1000000'],
            // PAKAI_KATALOG
            'material_id' => ['required_if:decision,'.ProjectMaterial::DECISION_PAKAI_KATALOG, 'nullable', 'integer', 'exists:materials,id'],
            'source' => [
                'required_if:decision,'.ProjectMaterial::DECISION_PAKAI_KATALOG,
                'nullable',
                Rule::in([ProjectMaterialSource::Gudang->value, ProjectMaterialSource::Pembelian->value]),
            ],
            // DAFTAR_KATALOG / CUSTOM — for DAFTAR_KATALOG `name` is the
            // catalog item's base name (structured identity, §5.5 Lapis 1).
            'name' => [$newItem, 'nullable', 'string', 'max:150'],
            'unit_id' => [$newItem, 'nullable', 'integer', new SelectableUnit],
            'spec' => ['nullable', 'string', 'max:150'],
            'brand' => ['nullable', 'string', 'max:100'],
            'material_category_id' => [
                'required_if:decision,'.ProjectMaterial::DECISION_DAFTAR_KATALOG,
                'nullable',
                'integer',
                Rule::exists('material_categories', 'id')->where('is_active', true),
            ],
            // Required by MaterialCatalogService only when a similar item exists.
            'similar_reason' => ['nullable', 'string', 'max:500'],
            'warehouse_price' => ['required_if:decision,'.ProjectMaterial::DECISION_DAFTAR_KATALOG, 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'sell_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            // Approved purchase price per unit (PEMBELIAN / CUSTOM).
            'unit_price' => ['required_if:decision,'.ProjectMaterial::DECISION_CUSTOM, 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->where('is_active', true)],
            // TOLAK
            'reject_reason' => ['required_if:decision,'.ProjectMaterial::DECISION_TOLAK, 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'decision.required' => 'Keputusan wajib dipilih.',
            'decision.in' => 'Keputusan tidak valid.',
            'qty.required' => 'Jumlah yang disetujui wajib diisi.',
            'qty.min' => 'Jumlah minimal 0,01.',
            'qty.decimal' => 'Jumlah maksimal 2 angka di belakang koma.',
            'material_id.required_if' => 'Pilih barang katalog yang dipakai.',
            'source.required_if' => 'Pilih sumber: ambil dari gudang atau dibeli untuk proyek.',
            'name.required_if' => 'Nama barang wajib diisi.',
            'unit_id.required_if' => 'Satuan wajib dipilih.',
            'warehouse_price.required_if' => 'Harga gudang barang baru wajib diisi.',
            'material_category_id.required_if' => 'Kategori barang wajib dipilih.',
            'material_category_id.exists' => 'Kategori tidak ditemukan atau sudah nonaktif.',
            'unit_price.required_if' => 'Harga per satuan wajib diisi.',
            'vendor_id.exists' => 'Vendor tidak ditemukan atau sudah nonaktif.',
            'reject_reason.required_if' => 'Alasan penolakan wajib diisi.',
        ];
    }
}
