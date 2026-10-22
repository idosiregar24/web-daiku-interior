<?php

namespace App\Http\Requests\HR;

use App\Enums\DailyActivityReviewStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sprint 29 Sub 13 (I2) — HR's check of one employee's day. Which status
 * fits the day (Sesuai needs entries, Tidak mengisi an empty working day)
 * is DailyActivityService::review().
 */
class ReviewDailyActivityRequest extends FormRequest
{
    public const NOTE_MAX = 1000;

    /** Route-level `role:HR` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', Rule::enum(DailyActivityReviewStatus::class)],
            'note' => [
                Rule::requiredIf($this->input('status') === DailyActivityReviewStatus::PerluKlarifikasi->value),
                'nullable',
                'string',
                'max:'.self::NOTE_MAX,
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Pilih hasil pemeriksaan.',
            'note.required' => 'Catatan klarifikasi wajib diisi.',
            'note.max' => 'Catatan maksimal '.self::NOTE_MAX.' karakter.',
        ];
    }
}
