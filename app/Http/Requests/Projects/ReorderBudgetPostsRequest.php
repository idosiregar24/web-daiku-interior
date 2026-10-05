<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

/** Sprint 12 #24 — the project's posts in their new order (ProjectBudgetService checks it's all of them). */
class ReorderBudgetPostsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageBudget', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'post_ids' => ['required', 'array', 'max:200'],
            'post_ids.*' => ['integer', 'distinct'],
        ];
    }
}
