<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sprint 12 #27 — a realisation of an allocated item (real qty × harga
 * modal, optional vendor from Master Vendor). With `reason` it is the
 * overrun request to the CEO (#28) instead. Only the project's PM.
 */
class RecordRealizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageBudget', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'qty_actual' => ['required', 'numeric', 'min:0.01', 'max:9999999999'],
            'unit_cost' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->where('is_active', true)],
            'note' => ['nullable', 'string', 'max:1000'],
            'reason' => [$this->routeIs('projects.budget.overruns.store') ? 'required' : 'prohibited', 'string', 'min:5', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'qty_actual.required' => 'Qty riil wajib diisi.',
            'qty_actual.min' => 'Qty riil minimal 0,01.',
            'unit_cost.required' => 'Harga modal wajib diisi.',
            'unit_cost.min' => 'Harga modal tidak boleh negatif.',
            'vendor_id.exists' => 'Vendor tidak ditemukan atau sudah nonaktif.',
            'note.max' => 'Catatan maksimal 1000 karakter.',
            'reason.required' => 'Alasan pengajuan ke CEO wajib diisi.',
            'reason.min' => 'Alasan minimal 5 karakter.',
        ];
    }
}
