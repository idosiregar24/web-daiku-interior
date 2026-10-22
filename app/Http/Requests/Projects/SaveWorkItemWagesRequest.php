<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 33 — the Upah Tukang table saved with one "Simpan": every changed
 * Satuan Upah at once. Route `role:QA|ADMIN_PROYEK` + ProjectPolicy::setWages()
 * (in the controller); the items must belong to the project (WorkItemService).
 */
class SaveWorkItemWagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.unit_wage' => ['present', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            // Sprint 37 — the row's Tim (a Tim Tukang template) or Kepala Tim (a Tukang / a typed name).
            'items.*.work_team_id' => ['sometimes', 'nullable', 'integer', 'exists:work_teams,id'],
            'items.*.team_lead_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'items.*.team_lead_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Tidak ada upah yang diubah.',
            'items.*.unit_wage.numeric' => 'Satuan upah harus berupa angka.',
            'items.*.unit_wage.min' => 'Satuan upah tidak boleh negatif.',
            'items.*.unit_wage.max' => 'Satuan upah terlalu besar.',
            'items.*.work_team_id.exists' => 'Template tim tidak ditemukan.',
            'items.*.team_lead_id.exists' => 'Kepala Tim tidak ditemukan.',
            'items.*.team_lead_name.max' => 'Nama Kepala Tim terlalu panjang.',
        ];
    }
}
