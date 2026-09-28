<?php

namespace App\Http\Requests\Finance;

use Illuminate\Validation\Rule;

class UpdateFinanceAllocationConfigRequest extends StoreFinanceAllocationConfigRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'label' => ['required', 'string', 'max:50', Rule::unique('finance_allocation_configs', 'label')->ignore($this->route('allocation'))],
        ];
    }
}
