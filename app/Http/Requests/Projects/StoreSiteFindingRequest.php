<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

/** Sprint 24 D12 — a finishing finding on site (route `role:QA` + ProjectPolicy::supervise()). */
class StoreSiteFindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('supervise', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'min:3', 'max:2000'],
            'milestone_id' => ['nullable', 'integer'],
            'photo_url' => ['nullable', 'string', 'url:http,https', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'description.required' => 'Uraian temuan wajib diisi.',
            'description.min' => 'Uraian temuan minimal 3 karakter.',
            'photo_url.url' => 'Link foto harus berupa URL yang valid (http/https).',
        ];
    }
}
