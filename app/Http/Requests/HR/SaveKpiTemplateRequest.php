<?php

namespace App\Http\Requests\HR;

use App\Enums\KpiDirection;
use App\Enums\KpiIndicatorSource;
use App\Services\Kpi\KpiMetricRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * SDM (Sprint 10, §3.3) — a position's KPI template: its full indicator
 * list. Weights must total exactly 100; AUTO indicators need a metric the
 * system knows, MANUAL ones must not have one. KpiService re-checks.
 */
class SaveKpiTemplateRequest extends FormRequest
{
    /** Route-level `role:HR` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $metrics = app(KpiMetricRegistry::class)->keys();

        return [
            'indicators' => ['required', 'array', 'min:1', 'max:20'],
            'indicators.*.id' => ['nullable', 'integer'],
            'indicators.*.name' => ['required', 'string', 'max:150'],
            'indicators.*.source' => ['required', Rule::enum(KpiIndicatorSource::class)],
            'indicators.*.metric_key' => [
                'nullable',
                'string',
                'required_if:indicators.*.source,'.KpiIndicatorSource::Auto->value,
                'prohibited_if:indicators.*.source,'.KpiIndicatorSource::Manual->value,
                Rule::in($metrics),
            ],
            'indicators.*.target' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'indicators.*.weight' => ['required', 'numeric', 'gt:0', 'max:100'],
            'indicators.*.direction' => ['required', Rule::enum(KpiDirection::class)],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $total = collect($this->input('indicators', []))->sum(fn ($row) => (float) ($row['weight'] ?? 0));

                if (abs($total - 100) > 0.001) {
                    $validator->errors()->add('indicators', 'Total bobot harus tepat 100% (sekarang '.rtrim(rtrim(number_format($total, 2, ',', ''), '0'), ',').'%).');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'indicators.required' => 'Template KPI minimal berisi 1 indikator.',
            'indicators.min' => 'Template KPI minimal berisi 1 indikator.',
            'indicators.max' => 'Template KPI maksimal berisi 20 indikator.',
            'indicators.*.name.required' => 'Nama indikator wajib diisi.',
            'indicators.*.name.max' => 'Nama indikator maksimal 150 karakter.',
            'indicators.*.source.required' => 'Sumber indikator wajib dipilih.',
            'indicators.*.source.enum' => 'Sumber indikator tidak valid.',
            'indicators.*.metric_key.required_if' => 'Indikator otomatis wajib memilih metrik.',
            'indicators.*.metric_key.prohibited_if' => 'Indikator manual tidak memakai metrik otomatis.',
            'indicators.*.metric_key.in' => 'Metrik otomatis tidak dikenal sistem.',
            'indicators.*.target.required' => 'Target wajib diisi.',
            'indicators.*.target.numeric' => 'Target harus berupa angka.',
            'indicators.*.target.gt' => 'Target harus lebih dari 0.',
            'indicators.*.target.max' => 'Target terlalu besar.',
            'indicators.*.weight.required' => 'Bobot wajib diisi.',
            'indicators.*.weight.numeric' => 'Bobot harus berupa angka.',
            'indicators.*.weight.gt' => 'Bobot harus lebih dari 0.',
            'indicators.*.weight.max' => 'Bobot maksimal 100%.',
            'indicators.*.direction.required' => 'Arah penilaian wajib dipilih.',
            'indicators.*.direction.enum' => 'Arah penilaian tidak valid.',
        ];
    }
}
