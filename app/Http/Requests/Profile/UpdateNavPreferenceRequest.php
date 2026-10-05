<?php

namespace App\Http\Requests\Profile;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNavPreferenceRequest extends FormRequest
{
    /**
     * The sidebar group labels that can be collapsed — must match the
     * `label`s of NAV_GROUPS in resources/js/Layouts/AppLayout.tsx
     * (⚙ Pengaturan is a pinned bottom menu, not a collapsible group).
     */
    public const GROUPS = ['Utama', 'Presales', 'Eksekusi', 'Keuangan', 'Logistik', 'SDM', 'Eksekutif'];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'collapsed_groups' => ['present', 'array', 'max:'.count(self::GROUPS)],
            'collapsed_groups.*' => ['string', 'distinct', Rule::in(self::GROUPS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'collapsed_groups.*.in' => 'Grup menu tidak dikenal.',
        ];
    }
}
