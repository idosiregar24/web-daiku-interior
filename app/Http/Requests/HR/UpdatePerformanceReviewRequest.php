<?php

namespace App\Http\Requests\HR;

use App\Enums\ReviewRecommendation;
use App\Services\PerformanceReviewService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * SDM (Sprint 10, §3.4) — HR saves a DRAFT review. Aspects may stay empty
 * while drafting (submit requires all four — PerformanceReviewService);
 * weights are whole percents totalling 100. Mirrored by the Zod schema in
 * Pages/HR/Reviews/Show.tsx.
 */
class UpdatePerformanceReviewRequest extends FormRequest
{
    /** Route-level `role:HR` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'qualitative' => ['required', 'array'],
            'weights' => ['required', 'array'],
            'recommendation' => ['nullable', 'string', Rule::in(array_column(ReviewRecommendation::cases(), 'value'))],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        foreach (PerformanceReviewService::ASPECTS as $aspect) {
            $rules["qualitative.{$aspect}"] = ['nullable', 'integer', 'between:1,5'];
        }

        foreach (array_keys(PerformanceReviewService::DEFAULT_WEIGHTS) as $part) {
            $rules["weights.{$part}"] = ['required', 'integer', 'between:0,100'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['weights', 'weights.kpi', 'weights.qualitative', 'weights.discipline'])) {
                    return;
                }

                $total = array_sum(array_map('intval', $this->input('weights', [])));

                if ($total !== 100) {
                    $validator->errors()->add('weights', "Total bobot harus 100% (sekarang {$total}%).");
                }
            },
        ];
    }

    public function messages(): array
    {
        $messages = [
            'qualitative.required' => 'Penilaian kualitatif wajib dikirim.',
            'weights.required' => 'Bobot wajib diisi.',
            'recommendation.in' => 'Rekomendasi tidak valid.',
            'notes.max' => 'Catatan maksimal 2000 karakter.',
        ];

        foreach (PerformanceReviewService::ASPECT_LABELS as $aspect => $label) {
            $messages["qualitative.{$aspect}.integer"] = "Nilai {$label} harus 1 sampai 5.";
            $messages["qualitative.{$aspect}.between"] = "Nilai {$label} harus 1 sampai 5.";
        }

        foreach (['kpi' => 'KPI', 'qualitative' => 'kualitatif', 'discipline' => 'kedisiplinan'] as $part => $label) {
            $messages["weights.{$part}.required"] = "Bobot {$label} wajib diisi.";
            $messages["weights.{$part}.integer"] = "Bobot {$label} harus bilangan bulat.";
            $messages["weights.{$part}.between"] = "Bobot {$label} harus 0 sampai 100.";
        }

        return $messages;
    }
}
