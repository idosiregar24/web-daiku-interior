<?php

namespace App\Http\Requests\Quotation;

use App\Enums\PaymentTermTrigger;
use App\Services\QuotationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePaymentTermsRequest extends FormRequest
{
    /** Route-level `role:ESTIMATOR` middleware gates this (Sprint 12 #12 — the Estimator drafts the scheme). */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Shape only — "percentages add up to 100%" and "a dated row needs its
     * date" are checked in QuotationService::savePaymentTerms() together
     * with the amounts, which are derived there and never sent.
     */
    public function rules(): array
    {
        return [
            'terms' => ['required', 'array', 'min:1', 'max:'.QuotationService::MAX_PAYMENT_TERMS],
            'terms.*.label' => ['required', 'string', 'max:100'],
            'terms.*.percentage' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:100'],
            'terms.*.trigger' => ['required', Rule::enum(PaymentTermTrigger::class)],
            'terms.*.due_date' => ['nullable', 'date'],
            'terms.*.milestone_name' => ['nullable', 'string', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'terms.required' => 'Skema pembayaran berisi minimal satu baris.',
            'terms.max' => 'Skema pembayaran maksimal '.QuotationService::MAX_PAYMENT_TERMS.' baris (termasuk DP).',
            'terms.*.label.required' => 'Nama termin wajib diisi.',
            'terms.*.percentage.required' => 'Persentase wajib diisi.',
            'terms.*.percentage.gt' => 'Persentase harus lebih dari 0.',
            'terms.*.percentage.max' => 'Persentase maksimal 100.',
            'terms.*.trigger.required' => 'Pemicu termin wajib dipilih.',
        ];
    }
}
