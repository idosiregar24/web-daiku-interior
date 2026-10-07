<?php

namespace App\Http\Requests\Profile;

use App\Enums\NotificationCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Sprint 18 Sub 05 — Pengaturan Notifikasi (own account, every role). */
class UpdateNotificationPreferenceRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'muted_categories' => ['present', 'array', 'max:'.count(NotificationCategory::cases())],
            'muted_categories.*' => ['string', 'distinct', Rule::enum(NotificationCategory::class)],
            'sound' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'muted_categories.*.enum' => 'Kategori notifikasi tidak dikenal.',
            'sound.required' => 'Pilih apakah notifikasi berbunyi.',
        ];
    }
}
