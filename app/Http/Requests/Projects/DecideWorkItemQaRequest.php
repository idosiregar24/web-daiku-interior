<?php

namespace App\Http\Requests\Projects;

use App\Models\WorkItemQaCheck;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 29 Sub 12 (G1) — the Pengawas's QA decision on RAB items (route
 * `role:QA` + ProjectPolicy::reviewItemQa()). LOLOS for one or more items,
 * PERBAIKAN for one with a note; which items may be decided is checked in
 * WorkItemQaService.
 */
class DecideWorkItemQaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reviewItemQa', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'in:LOLOS,PERBAIKAN'],
            'item_ids' => ['required', 'array', 'min:1', 'max:500'],
            'item_ids.*' => ['integer'],
            'note' => ['nullable', 'required_if:decision,PERBAIKAN', 'string', 'max:1000'],
            'photo_url' => ['nullable', 'string', 'url:http,https', 'max:500'],
            'photo' => ['nullable', 'file', 'mimes:'.implode(',', WorkItemQaCheck::PHOTO_MIMES), 'max:'.WorkItemQaCheck::MAX_PHOTO_KB],
        ];
    }

    public function messages(): array
    {
        return [
            'decision.in' => 'Keputusan QA tidak valid.',
            'item_ids.required' => 'Pilih item yang diperiksa.',
            'note.required_if' => 'Tulis apa yang perlu diperbaiki.',
            'photo_url.url' => 'Link foto harus berupa URL yang valid (http/https).',
            'photo.mimes' => 'Foto harus JPG, PNG, atau WEBP.',
            'photo.max' => 'Ukuran foto maksimal 8 MB.',
        ];
    }
}
