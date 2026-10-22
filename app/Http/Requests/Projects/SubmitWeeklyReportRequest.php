<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 24 D10 — the Pengawas's Friday report (route `role:QA` + ProjectPolicy::supervise()).
 * Sprint 29 Sub 09 — + the pengecekan % per RAB item (`progress`, the % added
 * this time; a negative correction needs a note). The 0–100 % limit per item
 * and "items with a wage of this project" are checked in WorkItemService.
 */
class SubmitWeeklyReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('supervise', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'notes' => ['required', 'string', 'min:5', 'max:5000'],
            'links' => ['nullable', 'array', 'max:20'],
            'links.*' => ['bail', 'required', 'string', 'url:http,https', 'max:2048'],
            ...self::progressRules(),
        ];
    }

    /** Shared with SaveWorkCheckDraftRequest. */
    public static function progressRules(): array
    {
        return [
            'progress' => ['nullable', 'array', 'max:500'],
            'progress.*.item_id' => ['required', 'integer'],
            'progress.*.percent' => ['nullable', 'numeric', 'min:-100', 'max:100', 'decimal:0,2'],
            // Required for a negative % (a correction) — WorkItemService::recordProgress().
            'progress.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'notes.required' => 'Catatan pengecekan wajib diisi.',
            'notes.min' => 'Catatan pengecekan minimal 5 karakter.',
            'notes.max' => 'Catatan pengecekan maksimal 5000 karakter.',
            'links.max' => 'Maksimal 20 link.',
            'links.*.required' => 'Link tidak boleh kosong.',
            'links.*.url' => 'Link harus berupa URL yang valid (http/https).',
            ...self::progressMessages(),
        ];
    }

    public static function progressMessages(): array
    {
        return [
            'progress.max' => 'Terlalu banyak item.',
            'progress.*.item_id.required' => 'Item tidak valid.',
            'progress.*.percent.numeric' => 'Persen harus berupa angka.',
            'progress.*.percent.min' => 'Persen minimal -100.',
            'progress.*.percent.max' => 'Persen maksimal 100.',
            'progress.*.percent.decimal' => 'Persen maksimal 2 angka di belakang koma.',
            'progress.*.note.max' => 'Catatan maksimal 500 karakter.',
        ];
    }
}
