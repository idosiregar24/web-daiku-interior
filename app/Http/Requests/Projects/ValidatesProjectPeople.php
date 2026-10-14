<?php

namespace App\Http\Requests\Projects;

use App\Models\Project;
use App\Models\User;
use Closure;

/**
 * Sprint 23 K14/K15 — the people on a project, shared by "Buka Proyek"
 * (OpenProjectRequest) and "Ubah Proyek" (UpdateProjectRequest):
 *
 * - Kepala Tim: an active user (`team_lead_id`) **or** a typed name
 *   (`team_lead_name` — an outsider/vendor), exactly one of the two.
 * - Pengawas (QA/QC): optional, an active user with role QA.
 *
 * On Edit Proyek a person who is already on the project passes unchanged
 * even if since deactivated — only a change has to pick an active one.
 */
trait ValidatesProjectPeople
{
    /** @return array<string, list<mixed>> */
    protected function teamRules(): array
    {
        return [
            'team_lead_id' => [
                'nullable',
                'integer',
                'required_without:team_lead_name',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! $this->isCurrent('team_lead_id', $value) && ! User::query()->where('is_active', true)->whereKey($value)->exists()) {
                        $fail('Kepala Tim yang dipilih bukan karyawan aktif.');
                    }
                },
            ],
            'team_lead_name' => ['nullable', 'string', 'max:255', 'required_without:team_lead_id', 'prohibits:team_lead_id'],
            'supervisor_id' => ['nullable', 'integer', $this->holdsRole('QA', 'Pengawas harus user aktif dengan role QA.', keep: 'supervisor_id')],
        ];
    }

    /** @return array<string, string> */
    protected function teamMessages(): array
    {
        return [
            'team_lead_id.required_without' => 'Kepala Tim wajib diisi — pilih karyawan atau ketik namanya.',
            'team_lead_id.integer' => 'Kepala Tim tidak valid.',
            'team_lead_name.required_without' => 'Kepala Tim wajib diisi — pilih karyawan atau ketik namanya.',
            'team_lead_name.prohibits' => 'Isi Kepala Tim salah satu saja: pilih karyawan atau ketik nama.',
            'team_lead_name.max' => 'Nama Kepala Tim maksimal 255 karakter.',
            'supervisor_id.integer' => 'Pengawas tidak valid.',
        ];
    }

    /** `$keep` — the project column whose current value passes unchanged (Edit Proyek). */
    protected function holdsRole(string $role, string $message, ?string $keep = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($role, $message, $keep) {
            if ($keep !== null && $this->isCurrent($keep, $value)) {
                return;
            }

            if (! User::role($role)->where('is_active', true)->whereKey($value)->exists()) {
                $fail($message);
            }
        };
    }

    /** The value the edited project already has in `$column` (never at Buka Proyek — no `{project}` there). */
    private function isCurrent(string $column, mixed $value): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && $project->{$column} !== null && (int) $project->{$column} === (int) $value;
    }
}
