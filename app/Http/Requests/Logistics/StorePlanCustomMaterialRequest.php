<?php

namespace App\Http\Requests\Logistics;

use App\Rules\SelectableUnit;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 29 Sub 11 (E2) — "+ Bahan lain (custom)" in the Pengawas's /
 * Admin Proyek's Rencana Bahan: name, spec, qty and unit (a master — never
 * free text), a photo link and a note optional. Logistik matches it to the
 * catalog when the plan is submitted (MaterialRequestService::review()).
 */
class StorePlanCustomMaterialRequest extends FormRequest
{
    /** Route-level role middleware + ProjectPolicy::planMaterials() (in the controller) gate this. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'spec' => ['nullable', 'string', 'max:255'],
            'qty' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:1000000'],
            'unit_id' => ['required', 'integer', new SelectableUnit],
            'photo_link' => ['nullable', 'url', 'max:500'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama bahan wajib diisi.',
            'name.max' => 'Nama bahan maksimal 150 karakter.',
            'spec.max' => 'Spesifikasi maksimal 255 karakter.',
            'qty.required' => 'Jumlah wajib diisi.',
            'qty.numeric' => 'Jumlah harus berupa angka.',
            'qty.min' => 'Jumlah minimal 0,01.',
            'qty.max' => 'Jumlah terlalu besar.',
            'qty.decimal' => 'Jumlah maksimal 2 angka di belakang koma.',
            'unit_id.required' => 'Satuan wajib dipilih.',
            'photo_link.url' => 'Link foto harus berupa URL yang valid.',
            'note.max' => 'Catatan maksimal 1000 karakter.',
        ];
    }
}
