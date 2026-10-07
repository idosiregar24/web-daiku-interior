<?php

namespace App\Http\Requests\Concerns;

use App\Support\Phone;

/**
 * Sprint 16 Sub 07 — a lead's contact is a mobile number (`phone`), an
 * email, or both; at least one is required. The number is normalized
 * before validation ("+62 812-…" → "0812…") so only the canonical digits
 * are ever stored, and the email is lower-cased.
 */
trait ValidatesLeadContact
{
    protected function prepareLeadContact(): void
    {
        $this->merge([
            'phone' => Phone::normalize($this->input('phone')),
            'email' => ($email = trim((string) $this->input('email'))) === '' ? null : mb_strtolower($email),
        ]);
    }

    /** @return array<string, array<int, string>> */
    protected function leadContactRules(): array
    {
        return [
            'phone' => ['nullable', 'required_without:email', 'string', 'regex:'.Phone::PATTERN],
            'email' => ['nullable', 'required_without:phone', 'string', 'max:255', 'email:rfc'],
        ];
    }

    /** @return array<string, string> */
    protected function leadContactMessages(): array
    {
        return [
            'phone.required_without' => 'Isi No. HP atau email klien (minimal salah satu).',
            'email.required_without' => 'Isi No. HP atau email klien (minimal salah satu).',
            'phone.regex' => 'No. HP hanya angka dan diawali 08 (10–13 digit), mis. 081234567890.',
            'email.email' => 'Format email tidak valid, mis. nama@gmail.com.',
        ];
    }
}
