<?php

namespace App\Http\Requests\Logistics;

use App\Enums\ProjectMaterialSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sprint 29 Sub 11 (E3) — Logistik's Setujui / Tolak on submitted Rencana
 * Bahan lines, one or many. Approving may change the source and set the
 * vendor of a bought line; rejecting needs a reason.
 */
class DecideMaterialPlanRequest extends FormRequest
{
    public const MAX_LINES = 100;

    /** Route-level `role:LOGISTICS` gates this. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'ids.*' => ['required', 'integer', 'distinct'],
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['required_if:decision,reject', 'nullable', 'string', 'min:3', 'max:1000'],
            'source' => ['nullable', Rule::in([ProjectMaterialSource::Gudang->value, ProjectMaterialSource::Pembelian->value])],
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->where('is_active', true)],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Pilih minimal satu bahan.',
            'ids.min' => 'Pilih minimal satu bahan.',
            'ids.max' => 'Maksimal '.self::MAX_LINES.' bahan sekali proses.',
            'decision.required' => 'Pilih Setujui atau Tolak.',
            'decision.in' => 'Keputusan harus Setujui atau Tolak.',
            'reason.required_if' => 'Alasan penolakan wajib diisi.',
            'reason.min' => 'Alasan penolakan minimal 3 karakter.',
            'reason.max' => 'Alasan penolakan maksimal 1000 karakter.',
            'source.in' => 'Sumber harus Gudang atau Pembelian.',
            'vendor_id.exists' => 'Vendor tidak ditemukan atau sudah nonaktif.',
        ];
    }
}
