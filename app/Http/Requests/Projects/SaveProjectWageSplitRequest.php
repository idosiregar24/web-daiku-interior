<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 32 — one Kepala Tim's people on a project: a template (`work_team_id`)
 * and/or the members typed (route `role:QA|ADMIN_PROYEK` + ProjectPolicy::splitWages()).
 */
class SaveProjectWageSplitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('splitWages', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'team_label' => ['required', 'string', 'max:255'],
            'work_team_id' => ['nullable', 'integer', 'exists:work_teams,id'],
            ...SaveWorkTeamRequest::memberRules(required: false),
        ];
    }

    public function messages(): array
    {
        return [
            'members.*.weight.required' => 'Bobot wajib diisi.',
            'members.*.weight.gt' => 'Bobot harus lebih dari 0.',
        ];
    }
}
