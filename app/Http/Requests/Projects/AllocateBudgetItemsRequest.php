<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 12 #24 — RAB items into a post (`budget_post_id`) or back out of
 * their posts (no `budget_post_id`). That the items are this project's
 * RAB and the post this project's is checked by ProjectBudgetService /
 * the controller.
 */
class AllocateBudgetItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageBudget', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'budget_post_id' => ['nullable', 'integer'],
            'item_ids' => ['required', 'array', 'min:1', 'max:500'],
            'item_ids.*' => ['integer', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'item_ids.required' => 'Pilih minimal satu item.',
            'item_ids.min' => 'Pilih minimal satu item.',
        ];
    }
}
