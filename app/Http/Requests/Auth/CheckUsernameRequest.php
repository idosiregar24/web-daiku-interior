<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\Username;
use Illuminate\Foundation\Http\FormRequest;

class CheckUsernameRequest extends FormRequest
{
    /** Any signed-in user (route `auth`); who the check is *for* is decided in targetUser(). */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['username' => Username::normalize($this->input('username')) ?? '']);
    }

    public function rules(): array
    {
        return [
            'username' => ['present', 'nullable', 'string', 'max:100'],
            // The person's name — suggestions are built from it ("Buat dari nama").
            'name' => ['nullable', 'string', 'max:255'],
            'user_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * Whose current username counts as "unchanged". Only the CEO (and
     * SUPERADMIN) edit other accounts: they pass the edited user's id, or
     * nothing for a new account. Everyone else is always checked against
     * themselves, whatever `user_id` they send.
     */
    public function targetUser(): ?User
    {
        $actor = $this->user();

        if (! $actor->hasAnyRole(['CEO', 'SUPERADMIN'])) {
            return $actor;
        }

        return $this->filled('user_id') ? User::find($this->integer('user_id')) : null;
    }
}
