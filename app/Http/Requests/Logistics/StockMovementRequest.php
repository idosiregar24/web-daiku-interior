<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class StockMovementRequest extends FormRequest
{
    /** Route-level `role:LOGISTICS` middleware already gates this action (PRD §7.1 "Material – Stok": LOG CRUD). */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Shared by stock-in and stock-out; `project_id` is required only for
     * stock-out (PRD §4.8 "Pemakaian material wajib terhubung ke proyek")
     * and refused for stock-in. The "not more than available stock" rule
     * lives in StockService, under the row lock — checking it here would
     * race with a concurrent stock-out.
     */
    public function rules(): array
    {
        return [
            'qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'movement_date' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
            'project_id' => [
                $this->routeIs('logistics.materials.stockOut') ? 'required' : 'prohibited',
                'integer',
                'exists:projects,id',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'qty.required' => 'Jumlah wajib diisi.',
            'qty.min' => 'Jumlah minimal 1.',
            'movement_date.required' => 'Tanggal wajib diisi.',
            'movement_date.before_or_equal' => 'Tanggal tidak boleh di masa depan.',
            'project_id.required' => 'Pemakaian material wajib dikaitkan ke proyek.',
            'project_id.prohibited' => 'Penerimaan barang tidak dikaitkan ke proyek.',
            'project_id.exists' => 'Proyek tidak ditemukan.',
        ];
    }
}
