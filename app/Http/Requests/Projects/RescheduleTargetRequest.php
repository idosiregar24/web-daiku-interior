<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 24 D9 — "Geser" one target (`due_date`) or "Tukar" two days of a
 * project (`from_date` ↔ `to_date`, optionally one Tukang's). The timeline
 * bounds are TaskService's rule.
 */
class RescheduleTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->route('task')
            ? [
                'due_date' => ['required', 'date'],
                'note' => ['nullable', 'string', 'max:255'],
            ]
            : [
                'from_date' => ['required', 'date'],
                'to_date' => ['required', 'date', 'different:from_date'],
                'assignee_id' => ['nullable', 'integer'],
            ];
    }

    public function messages(): array
    {
        return [
            'due_date.required' => 'Tanggal baru wajib diisi.',
            'from_date.required' => 'Tanggal pertama wajib diisi.',
            'to_date.required' => 'Tanggal kedua wajib diisi.',
            'to_date.different' => 'Pilih dua tanggal yang berbeda.',
        ];
    }
}
