<?php

namespace App\Http\Requests\Projects;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** Sprint 12 decision #19 — the CEO's "Buka Proyek". Route-level `role:CEO` gates it. */
class OpenProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'pm_id' => ['required', 'integer', $this->holdsRole('PM', 'PM yang dipilih bukan Project Manager aktif.')],
            // D2 — optional; the PM may change it later (Sub 11).
            'assistant_pm_id' => ['nullable', 'integer', $this->holdsRole('ASISTEN_PM', 'Asisten PM yang dipilih tidak valid.')],
            'start_date' => ['required', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama proyek wajib diisi.',
            'pm_id.required' => 'Project Manager wajib dipilih.',
            'start_date.required' => 'Tanggal mulai wajib diisi.',
        ];
    }

    private function holdsRole(string $role, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($role, $message) {
            if (! User::role($role)->where('is_active', true)->whereKey($value)->exists()) {
                $fail($message);
            }
        };
    }
}
