<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

/** Sprint 24 D10 — the Pengawas's Friday report (route `role:QA` + ProjectPolicy::supervise()). */
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
        ];
    }
}
