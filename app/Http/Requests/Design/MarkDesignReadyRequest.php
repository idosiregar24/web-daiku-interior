<?php

namespace App\Http\Requests\Design;

use App\Models\Design;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 22 — the architect's "Desain Siap Dikirim" (route `role:DESIGNER`):
 * only the design's own team or a Kepala Desain (DesignPolicy::update), with
 * an optional word to Marketing.
 */
class MarkDesignReadyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $design = $this->route('design');

        return $design instanceof Design && $this->user()->can('update', $design);
    }

    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.max' => 'Catatan untuk Marketing maksimal 500 karakter.',
        ];
    }
}
