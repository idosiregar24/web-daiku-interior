<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

/** A project's Timeline (UI name of a milestone, Sprint 23 K16) — mirrored by MilestoneFormDialog. */
class StoreMilestoneRequest extends FormRequest
{
    /** Route-level `role:CEO|PM|ASISTEN_PM` + ProjectPolicy::manageWork() gate this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Sprint 23 K17 — every new timeline runs from a start to an end date.
            'start_date' => ['required', 'date'],
            'target_date' => ['required', 'date', 'after_or_equal:start_date'],
            // Sprint 35 — the RAB items worked on in this timeline (checked against the project in MilestoneService).
            'work_item_ids' => ['sometimes', 'array', 'max:500'],
            'work_item_ids.*' => ['integer', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return self::timelineMessages();
    }

    /** @return array<string, string> Shared with UpdateMilestoneRequest. */
    public static function timelineMessages(): array
    {
        return [
            'name.required' => 'Nama timeline wajib diisi.',
            'name.max' => 'Nama timeline maksimal 255 karakter.',
            'start_date.required' => 'Tanggal mulai timeline wajib diisi.',
            'start_date.date' => 'Tanggal mulai tidak valid.',
            'target_date.required' => 'Tanggal selesai timeline wajib diisi.',
            'target_date.date' => 'Tanggal selesai tidak valid.',
            'target_date.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
        ];
    }
}
