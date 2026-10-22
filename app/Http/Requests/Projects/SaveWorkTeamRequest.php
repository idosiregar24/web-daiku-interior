<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Sprint 32 — a "Tim Tukang" template (route `role:CEO|PM|QA|ADMIN_PROYEK`); the rest is checked in WageSplitService. */
class SaveWorkTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('work_teams', 'name')->ignore($this->route('workTeam'))],
            'leader_name' => ['nullable', 'string', 'max:120'],
            ...self::memberRules(required: true),
        ];
    }

    /** Shared with SaveProjectWageSplitRequest. */
    public static function memberRules(bool $required): array
    {
        return [
            'members' => [$required ? 'required' : 'nullable', 'array', 'max:30'],
            'members.*.name' => ['nullable', 'string', 'max:120'],
            'members.*.level' => ['nullable', 'string', 'max:60'],
            'members.*.weight' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'members.*.company' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama tim wajib diisi.',
            'name.unique' => 'Nama tim ini sudah dipakai.',
            'members.required' => 'Isi minimal satu anggota tim.',
            'members.*.weight.required' => 'Bobot wajib diisi.',
            'members.*.weight.gt' => 'Bobot harus lebih dari 0.',
        ];
    }
}
