<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Editing a Timeline (Sprint 23 K16/K17) — mirrored by MilestoneFormDialog. */
class UpdateMilestoneRequest extends FormRequest
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
            // Sprint 23 K17 — older timelines were backfilled, so every row has one.
            'start_date' => ['required', 'date'],
            'target_date' => ['required', 'date', 'after_or_equal:start_date'],
            'status' => ['required', Rule::in(['PENDING', 'IN_PROGRESS', 'QA_WAITING', 'COMPLETED', 'OVERDUE'])],
        ];
    }

    public function messages(): array
    {
        return [
            ...StoreMilestoneRequest::timelineMessages(),
            'status.required' => 'Status wajib dipilih.',
        ];
    }
}
