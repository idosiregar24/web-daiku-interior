<?php

namespace App\Http\Requests\Projects;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** Sprint 29 Sub 08 (B3) — one row of the Upah Tukang card: Satuan Upah and/or Kepala Tim. */
class UpdateWorkItemRequest extends FormRequest
{
    /** Route `role:` + ProjectPolicy::setWages() (in the controller) gate this. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unit_wage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'team_lead_id' => ['sometimes', 'nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) {
                if (! User::query()->where('is_active', true)->whereKey($value)->exists()) {
                    $fail('Kepala Tim yang dipilih bukan karyawan aktif.');
                }
            }],
            'team_lead_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'unit_wage.numeric' => 'Satuan upah harus berupa angka.',
            'unit_wage.min' => 'Satuan upah tidak boleh negatif.',
            'unit_wage.max' => 'Satuan upah terlalu besar.',
            'team_lead_name.max' => 'Nama Kepala Tim maksimal 255 karakter.',
        ];
    }
}
