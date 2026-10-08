<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesUserIdentity;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Profil Saya — Sprint 21 (K1): the user sets their own username and
 * email; at least one stays filled. The 40-day username limit (K4) is
 * enforced in UserService::updateProfile().
 */
class ProfileUpdateRequest extends FormRequest
{
    use ValidatesUserIdentity;

    protected function prepareForValidation(): void
    {
        $this->prepareUserIdentity();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            ...$this->userIdentityRules($this->user()),
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama wajib diisi.',
            ...$this->userIdentityMessages(),
        ];
    }
}
