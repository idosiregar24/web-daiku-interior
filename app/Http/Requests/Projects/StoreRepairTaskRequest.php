<?php

namespace App\Http\Requests\Projects;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** Sprint 24 D12 — "Jadikan Task Perbaikan": an active Tukang and a day (ProjectPolicy::manageTargets() in the controller). */
class StoreRepairTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assignee_id' => [
                'required',
                'integer',
                function (string $attribute, mixed $value, Closure $fail) {
                    $staff = User::find($value);

                    if (! $staff || ! $staff->hasRole('FIELD_STAFF') || ! $staff->is_active) {
                        $fail('Pilih tukang (Field Staff) yang masih aktif.');
                    }
                },
            ],
            'due_date' => ['required', 'date', 'after_or_equal:today'],
            'title' => ['nullable', 'string', 'max:150'],
            'rate_per_task' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ];
    }

    public function messages(): array
    {
        return [
            'assignee_id.required' => 'Tukang wajib dipilih.',
            'due_date.required' => 'Tanggal perbaikan wajib diisi.',
            'due_date.after_or_equal' => 'Tanggal perbaikan tidak boleh di masa lalu.',
            'title.max' => 'Judul maksimal 150 karakter.',
        ];
    }
}
