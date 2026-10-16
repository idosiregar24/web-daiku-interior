<?php

namespace App\Http\Requests\Design;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 24 D2 — the Gambar Kerja links (Drive / PDF) and a short note.
 * Route `role:DESIGNER`, narrowed by WorkingDrawingPolicy::work(); the state
 * rules (assigned yet, not final) live in WorkingDrawingService::updateLinks().
 */
class UpdateWorkingDrawingLinksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('work', $this->route('workingDrawing'));
    }

    public function rules(): array
    {
        return [
            'drawing_urls' => ['nullable', 'array', 'max:20'],
            'drawing_urls.*' => ['bail', 'required', 'string', 'url:http,https', 'max:2048'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'drawing_urls.max' => 'Maksimal 20 link gambar kerja.',
            'drawing_urls.*.required' => 'Link gambar kerja tidak boleh kosong.',
            'drawing_urls.*.url' => 'Link gambar kerja harus berupa URL yang valid (http/https).',
            'drawing_urls.*.max' => 'Link gambar kerja maksimal 2048 karakter.',
            'note.max' => 'Catatan maksimal 2000 karakter.',
        ];
    }
}
