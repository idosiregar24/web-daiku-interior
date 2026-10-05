<?php

namespace App\Http\Requests\Design;

use App\Enums\DesignStatus;
use App\Models\Design;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateDesignRequest extends FormRequest
{
    /** @var list<int>|null Memoized — one query per request, not one per sub-staff row. */
    private ?array $assignableStaffIds = null;

    /**
     * Route-level `role:DESIGNER` (PRD §7.1 "Design Brief" — DES has CRUD),
     * narrowed by DesignPolicy::update() to the design's own architects or
     * a Kepala Desain (Sprint 12 #15).
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('design'));
    }

    /**
     * The status rule that depends on the design's state (post-ACC stages
     * need Client ACC) lives in DesignService::update(), next to the rest
     * of the status logic.
     */
    public function rules(): array
    {
        $brief = [
            'jenis_project' => ['nullable', Rule::in([
                'TOKO', 'CAFE', 'RENOVASI', 'KAMAR_SET', 'KITCHEN_SET',
                'KANTOR', 'ARSITEKTURAL', 'RUANG_TAMU_TV', 'RETAIL_TOKO', 'LAINNYA',
            ])],
            'brief_note' => ['nullable', 'string'],
            'problem' => ['nullable', 'string'],
            'design_urls' => ['nullable', 'array'],
            'design_urls.*' => ['url:http,https', 'max:2048'],
        ];

        /** @var Design $design */
        $design = $this->route('design');

        // Sprint 12: team, timeline and status of a design born from a RAB
        // Jasa Desain belong to the Kepala Desain / Marketing actions.
        if ($design->isFlowManaged()) {
            return $brief;
        }

        return [
            ...$brief,
            'pic_id' => ['required', 'exists:users,id'],
            // MENUNGGU_BAYAR / MENUNGGU_PENUGASAN are only ever set by the payment flow.
            'status' => ['required', (new Enum(DesignStatus::class))->except([DesignStatus::MenungguBayar, DesignStatus::MenungguPenugasan])],
            'target_hari' => ['nullable', 'integer', 'min:1'],
            'start_date' => ['nullable', 'date'],
            // PRD §4.2 "PIC & Sub-Staff" — the complete sub-staff list
            // (replaces the current one); omitted = left untouched.
            'staff' => ['sometimes', 'array', 'max:20'],
            'staff.*' => ['array'],
            'staff.*.user_id' => ['bail', 'required', 'integer', 'distinct', $this->staffMemberRule()],
            'staff.*.role_note' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'pic_id.required' => 'PIC utama wajib diisi.',
            'pic_id.exists' => 'PIC yang dipilih tidak ditemukan.',
            'status.required' => 'Status wajib dipilih.',
            'design_urls.*.url' => 'Link desain harus berupa URL yang valid.',
            'staff.max' => 'Maksimal 20 sub-staff per desain.',
            'staff.*.user_id.required' => 'Pilih arsitek untuk setiap baris sub-staff.',
            'staff.*.user_id.integer' => 'Sub-staff yang dipilih tidak valid.',
            'staff.*.user_id.distinct' => 'Arsitek yang sama dipilih lebih dari sekali.',
            'staff.*.role_note.max' => 'Peran sub-staff maksimal 100 karakter.',
        ];
    }

    /**
     * A sub-staff member is never the PIC, and must be an active DESIGNER
     * — except someone already on this design's team, who may stay after
     * being deactivated (history is kept, a re-save shouldn't fail).
     */
    private function staffMemberRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ((int) $value === (int) $this->input('pic_id')) {
                $fail('PIC utama tidak perlu ditambahkan lagi sebagai sub-staff.');

                return;
            }

            if (! in_array((int) $value, $this->assignableStaffIds(), true)) {
                $fail('Sub-staff harus arsitek yang masih aktif.');
            }
        };
    }

    /** @return list<int> */
    private function assignableStaffIds(): array
    {
        if ($this->assignableStaffIds === null) {
            /** @var Design $design */
            $design = $this->route('design');

            $this->assignableStaffIds = User::role('DESIGNER')->where('is_active', true)->pluck('id')
                ->merge($design->staff()->pluck('users.id'))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        return $this->assignableStaffIds;
    }
}
