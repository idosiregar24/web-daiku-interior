<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 29 Sub 09 — the draft of the next pengecekan (saved automatically):
 * the project's Pengawas or the Admin Proyek (route `role:QA|ADMIN_PROYEK` +
 * ProjectPolicy::fillWorkCheck()). Looser than sending: a draft may be
 * incomplete; the limits are checked when the Pengawas sends it.
 */
class SaveWorkCheckDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('fillWorkCheck', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:5000'],
            'links' => ['nullable', 'array', 'max:20'],
            'links.*' => ['nullable', 'string', 'max:2048'],
            'progress' => ['nullable', 'array', 'max:500'],
            'progress.*.item_id' => ['required', 'integer'],
            'progress.*.percent' => ['nullable', 'numeric', 'min:-100', 'max:100', 'decimal:0,2'],
            'progress.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'notes.max' => 'Catatan pengecekan maksimal 5000 karakter.',
            'links.max' => 'Maksimal 20 link.',
            ...SubmitWeeklyReportRequest::progressMessages(),
        ];
    }
}
