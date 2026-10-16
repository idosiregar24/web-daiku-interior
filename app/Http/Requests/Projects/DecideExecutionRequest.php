<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 24 D4/D10 — the PM's decision on a Pengawas payment request or a
 * weekly report: approve (with an amount — the payment, or this week's
 * Jasa Pengawas) or reject (note required). Who may decide is checked in
 * the controller (ProjectPolicy::approveExecution()); the limits on the
 * amount (never above what is left of the fee) in SupervisorPaymentService.
 */
class DecideExecutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:approve,reject'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'note' => ['nullable', 'string', 'max:1000', 'required_if:decision,reject'],
        ];
    }

    public function messages(): array
    {
        return [
            'decision.required' => 'Pilih setujui atau tolak.',
            'decision.in' => 'Keputusan tidak valid.',
            'amount.numeric' => 'Nominal harus berupa angka.',
            'amount.min' => 'Nominal tidak boleh negatif.',
            'note.required_if' => 'Alasan penolakan wajib diisi.',
            'note.max' => 'Catatan maksimal 1000 karakter.',
        ];
    }
}
