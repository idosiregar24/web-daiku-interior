<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sprint 11 Sub 3 — one request for every step of a project material
 * line's lifecycle; the extra fields follow the route. The "fits in the
 * leftover / enough stock" checks live in ProjectMaterialService, under
 * the row lock — checking them here would race a concurrent action.
 */
class ProjectMaterialActionRequest extends FormRequest
{
    /** Route-level role middleware + ProjectPolicy::manageMaterials() (in the controller) gate this. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'qty' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:500'],
        ];

        return match (true) {
            $this->routeIs('project-materials.issue') => [
                ...$rules,
                'movement_date' => ['required', 'date', 'before_or_equal:today'],
            ],
            $this->routeIs('project-materials.purchase') => [
                ...$rules,
                'unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
                'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->where('is_active', true)],
                'purchase_date' => ['required', 'date', 'before_or_equal:today'],
            ],
            $this->routeIs('project-materials.return') => [
                ...$rules,
                'movement_date' => ['required', 'date', 'before_or_equal:today'],
                // CUSTOM lines only (the service requires one of the two):
                // map onto a catalog item, or register a new one (Sub 4).
                'material_id' => ['nullable', 'integer', 'exists:materials,id', 'prohibits:new_material'],
                'new_material' => ['nullable', 'array'],
                'new_material.name' => ['required_with:new_material', 'string', 'max:150'],
                'new_material.material_category_id' => ['required_with:new_material', 'integer', Rule::exists('material_categories', 'id')->where('is_active', true)],
                'new_material.spec' => ['nullable', 'string', 'max:150'],
                'new_material.brand' => ['nullable', 'string', 'max:100'],
                'new_material.similar_reason' => ['nullable', 'string', 'max:500'],
                'new_material.cost_price' => ['required_with:new_material', 'numeric', 'min:0', 'max:9999999999'],
                'new_material.sell_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            ],
            $this->routeIs('project-materials.waste') => [
                ...$rules,
                'reason' => ['required', 'string', 'max:500'],
            ],
            $this->routeIs('project-materials.handOver') => [
                ...$rules,
                'note' => ['required', 'string', 'max:500'],
            ],
            default => $rules,
        };
    }

    public function messages(): array
    {
        return [
            'qty.required' => 'Jumlah wajib diisi.',
            'qty.min' => 'Jumlah minimal 0,01.',
            'qty.decimal' => 'Jumlah maksimal 2 angka di belakang koma.',
            'movement_date.required' => 'Tanggal wajib diisi.',
            'movement_date.before_or_equal' => 'Tanggal tidak boleh di masa depan.',
            'purchase_date.required' => 'Tanggal pembelian wajib diisi.',
            'purchase_date.before_or_equal' => 'Tanggal pembelian tidak boleh di masa depan.',
            'unit_price.required' => 'Harga beli per satuan wajib diisi.',
            'vendor_id.exists' => 'Vendor tidak ditemukan atau sudah nonaktif.',
            'material_id.exists' => 'Barang katalog tidak ditemukan.',
            'material_id.prohibits' => 'Pilih barang katalog yang ada atau daftarkan barang baru — tidak keduanya.',
            'new_material.name.required_with' => 'Nama barang katalog baru wajib diisi.',
            'new_material.cost_price.required_with' => 'Harga gudang barang baru wajib diisi.',
            'new_material.material_category_id.required_with' => 'Kategori barang baru wajib dipilih.',
            'reason.required' => 'Alasan susut wajib diisi.',
            'note.required' => 'Catatan penyerahan ke klien wajib diisi.',
        ];
    }
}
