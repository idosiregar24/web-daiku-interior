<?php

namespace App\Http\Requests\Concerns;

use App\Models\User;
use App\Support\Username;
use Illuminate\Validation\Rule;

/**
 * Sprint 21 (K1) — an account is identified by a username, an email, or
 * both; at least one is required. Used by Buat User, Edit User and Profil
 * Saya. The username is lower-cased before validation (`Budi` → `budi`),
 * the email too; empty strings become null so "cleared" means NULL.
 * Mirrored in `resources/js/lib/username.ts` (`requireUsernameOrEmail`).
 */
trait ValidatesUserIdentity
{
    protected function prepareUserIdentity(): void
    {
        $email = trim((string) $this->input('email'));

        $this->merge([
            'username' => Username::normalize($this->input('username')),
            'email' => $email === '' ? null : mb_strtolower($email),
        ]);
    }

    /**
     * @param  User|null  $account  The account being edited (null = a new one).
     * @return array<string, array<int, mixed>>
     */
    protected function userIdentityRules(?User $account = null): array
    {
        // A username the account already has is kept as is — even one from
        // before these rules (the demo `pm`/`qa`) — so saving a form that
        // didn't touch it never fails on the format.
        $unchanged = $account?->username !== null && $this->input('username') === $account->username;

        return [
            'username' => [
                'nullable',
                'required_without:email',
                ...($unchanged ? ['string'] : [...Username::rules(), Rule::notIn(Username::RESERVED)]),
                Rule::unique('users', 'username')->ignore($account?->id),
            ],
            'email' => [
                'nullable',
                'required_without:username',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($account?->id),
            ],
        ];
    }

    /** @return array<string, string> */
    protected function userIdentityMessages(): array
    {
        return [
            'username.required_without' => 'Isi username atau email (minimal salah satu).',
            'email.required_without' => 'Isi username atau email (minimal salah satu).',
            'username.regex' => 'Username hanya huruf kecil dan angka, diawali huruf, 3–30 karakter, tanpa spasi, titik atau garis bawah.',
            'username.max' => 'Username maksimal 30 karakter.',
            'username.not_in' => 'Username ini tidak boleh dipakai.',
            'username.unique' => 'Username sudah dipakai.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email sudah terdaftar.',
        ];
    }
}
