<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 31 Sub 03 — one row of the "Jadwal per Item" card: the production
 * window and, optionally, the installation window (route `role:` +
 * ProjectPolicy::scheduleWorkItems() in the controller; the project's dates
 * are checked in WorkItemService::updateSchedule()).
 */
class UpdateWorkItemScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'production_start' => ['nullable', 'date_format:Y-m-d', 'required_with:production_end'],
            'production_end' => ['nullable', 'date_format:Y-m-d', 'required_with:production_start', 'after_or_equal:production_start'],
            'installation_start' => ['nullable', 'date_format:Y-m-d', 'required_with:installation_end'],
            'installation_end' => ['nullable', 'date_format:Y-m-d', 'required_with:installation_start', 'after_or_equal:installation_start'],
        ];
    }

    public function messages(): array
    {
        return [
            'production_start.required_with' => 'Isi tanggal mulai produksi.',
            'production_end.required_with' => 'Isi tanggal selesai produksi.',
            'production_end.after_or_equal' => 'Selesai produksi tidak boleh sebelum mulainya.',
            'installation_start.required_with' => 'Isi tanggal mulai pemasangan.',
            'installation_end.required_with' => 'Isi tanggal selesai pemasangan.',
            'installation_end.after_or_equal' => 'Selesai pemasangan tidak boleh sebelum mulainya.',
        ];
    }
}
