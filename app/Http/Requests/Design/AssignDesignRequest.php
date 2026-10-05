<?php

namespace App\Http\Requests\Design;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sprint 12 decision #15 — "Tugaskan Desain" (route `role:KEPALA_DESAIN`):
 * the PIC architect (the Kepala Desain may pick themself), assistants and
 * the timeline. Everyone picked must be an active architect.
 */
class AssignDesignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $architects = Rule::in(User::role('DESIGNER')->where('is_active', true)->pluck('id')->all());

        return [
            'pic_id' => ['required', 'integer', $architects],
            'assistant_ids' => ['nullable', 'array', 'max:20'],
            'assistant_ids.*' => ['integer', 'distinct', 'different:pic_id', $architects],
            'start_date' => ['required', 'date'],
            'target_hari' => ['required', 'integer', 'min:1', 'max:365'],
        ];
    }

    public function messages(): array
    {
        return [
            'pic_id.required' => 'PIC arsitek wajib dipilih.',
            'pic_id.in' => 'PIC harus arsitek yang masih aktif.',
            'assistant_ids.max' => 'Maksimal 20 asisten per desain.',
            'assistant_ids.*.in' => 'Asisten harus arsitek yang masih aktif.',
            'assistant_ids.*.distinct' => 'Asisten yang sama dipilih lebih dari sekali.',
            'assistant_ids.*.different' => 'PIC tidak perlu dipilih lagi sebagai asisten.',
            'start_date.required' => 'Tanggal mulai wajib diisi.',
            'target_hari.required' => 'Target hari wajib diisi.',
            'target_hari.min' => 'Target minimal 1 hari.',
            'target_hari.max' => 'Target maksimal 365 hari.',
        ];
    }
}
