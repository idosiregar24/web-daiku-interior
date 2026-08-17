<?php

namespace App\Http\Requests\Overtime;

use Illuminate\Foundation\Http\FormRequest;

class StoreOvertimeRequestRequest extends FormRequest
{
    /** Route-level `role:FIELD_STAFF` middleware already gates this action (PRD §7.1 "Overtime Request" row — STF has CR). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'exists:projects,id'],
            'task_id' => ['nullable', 'exists:tasks,id'],
            'hours' => ['required', 'numeric', 'min:0.5', 'max:24'],
            'rate_per_hour' => ['required', 'numeric', 'min:0'],
            'work_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'project_id.required' => 'Proyek wajib dipilih.',
            'hours.required' => 'Jam lembur wajib diisi.',
            'rate_per_hour.required' => 'Rate per jam wajib diisi.',
            'work_date.required' => 'Tanggal lembur wajib diisi.',
            'reason.required' => 'Alasan/keterangan lembur wajib diisi.',
        ];
    }
}
