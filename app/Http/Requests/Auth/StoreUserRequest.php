<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\ValidatesUserIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    use ValidatesUserIdentity;

    /** Route-level `role:CEO` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->prepareUserIdentity();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            ...$this->userIdentityRules(),
            'password' => ['required', Password::defaults()],
            'role' => ['required', 'string', 'exists:roles,name'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama wajib diisi.',
            ...$this->userIdentityMessages(),
            'password.required' => 'Password wajib diisi.',
            'role.required' => 'Role wajib dipilih.',
            'role.exists' => 'Role tidak valid.',
        ];
    }
}
