<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 12 #24 — create / rename a budget post (free name). Who may is
 * ProjectPolicy::manageBudget(); a duplicate name in the project is
 * refused by ProjectBudgetService.
 */
class SaveBudgetPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageBudget', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama pos wajib diisi.',
            'name.max' => 'Nama pos maksimal 100 karakter.',
        ];
    }
}
