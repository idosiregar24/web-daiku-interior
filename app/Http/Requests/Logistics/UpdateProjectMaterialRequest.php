<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectMaterialRequest extends FormRequest
{
    /** Route-level role middleware gates this (see routes/web.php "Project Material"). */
    public function authorize(): bool
    {
        return true;
    }

    /** The material itself can't be swapped — remove the plan and add another instead. */
    public function rules(): array
    {
        return [
            'qty_planned' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'qty_planned.required' => 'Jumlah kebutuhan wajib diisi.',
        ];
    }
}
