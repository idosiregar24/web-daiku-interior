<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 31 Sub 07 — "Jadwalkan berurutan" on the Lembar Progres: several
 * items get their windows one after another (route `role:` +
 * ProjectPolicy::scheduleWorkItems() in the controller; the dates are checked
 * per item in WorkItemService::updateSchedule()).
 */
class ScheduleWorkItemsSequenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_ids' => ['required', 'array', 'min:1', 'max:500'],
            'item_ids.*' => ['integer'],
            'production_start' => ['required', 'date_format:Y-m-d'],
            'production_days' => ['required', 'integer', 'min:1', 'max:365'],
            'gap_days' => ['nullable', 'integer', 'min:0', 'max:60'],
            'installation_start' => ['nullable', 'date_format:Y-m-d', 'required_with:installation_days', 'after_or_equal:production_start'],
            'installation_days' => ['nullable', 'integer', 'min:1', 'max:365', 'required_with:installation_start'],
        ];
    }

    public function messages(): array
    {
        return [
            'item_ids.required' => 'Pilih item yang dijadwalkan.',
            'production_start.required' => 'Isi tanggal mulai produksi item pertama.',
            'production_days.required' => 'Isi lama produksi tiap item (hari).',
            'installation_start.required_with' => 'Isi tanggal mulai pemasangan item pertama.',
            'installation_start.after_or_equal' => 'Pemasangan tidak boleh mulai sebelum produksi.',
            'installation_days.required_with' => 'Isi lama pemasangan tiap item (hari).',
        ];
    }
}
