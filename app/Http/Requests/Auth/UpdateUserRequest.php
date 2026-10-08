<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\ValidatesUserIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
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
            ...$this->userIdentityRules($this->route('user')),
            'password' => ['nullable', Password::defaults()],
            'role' => ['required', 'string', 'exists:roles,name'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama wajib diisi.',
            ...$this->userIdentityMessages(),
            'role.required' => 'Role wajib dipilih.',
            'role.exists' => 'Role tidak valid.',
        ];
    }
}
