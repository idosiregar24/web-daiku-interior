<?php

namespace App\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 29 Sub 13 — one "Aktivitas Harian" entry (add and same-day edit).
 * Who may write is DailyActivityPolicy (checked in the controller); the
 * project must be one the writer sees running (DailyActivityService).
 */
class DailyActivityRequest extends FormRequest
{
    public const DESCRIPTION_MAX = 5000;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:'.self::DESCRIPTION_MAX],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'description.required' => 'Aktivitas wajib diisi.',
            'description.max' => 'Aktivitas maksimal '.self::DESCRIPTION_MAX.' karakter.',
            'project_id.exists' => 'Proyek tidak ditemukan.',
        ];
    }
}
