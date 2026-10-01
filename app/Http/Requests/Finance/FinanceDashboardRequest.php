<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/** Finance Dashboard's month picker for the per-account cash flow section. */
class FinanceDashboardRequest extends FormRequest
{
    /** Route-level `role:CEO|PM|FINANCE` middleware already gates this page ("Analytics – Per Divisi", PRD §7.1). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'month' => ['nullable', 'date_format:Y-m'],
        ];
    }

    public function messages(): array
    {
        return [
            'month.date_format' => 'Bulan tidak valid.',
        ];
    }

    /** First day of the chosen month, or of the current month. */
    public function month(): Carbon
    {
        $month = $this->validated('month');

        // `!` = day 1 — without it the 29th–31st overflow into the next month.
        return $month ? Carbon::createFromFormat('!Y-m', $month) : now()->startOfMonth();
    }
}
