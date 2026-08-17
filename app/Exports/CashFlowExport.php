<?php

namespace App\Exports;

use App\Models\FinanceTransaction;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * "Export Excel laporan cash flow bulanan: Laravel Excel" — one row per
 * FinanceTransaction, last 6 months, for Finance/Dashboard's export button.
 */
class CashFlowExport implements FromCollection, WithHeadings, WithMapping
{
    public function collection(): Collection
    {
        return FinanceTransaction::query()
            ->with(['project:id,name', 'bankAccount:id,label'])
            ->where('date', '>=', now()->subMonths(5)->startOfMonth()->toDateString())
            ->orderBy('date')
            ->get();
    }

    public function headings(): array
    {
        return ['Tanggal', 'Proyek', 'Rekening', 'Jenis', 'Kategori', 'Deskripsi', 'Nominal'];
    }

    public function map($transaction): array
    {
        return [
            $transaction->date->format('Y-m-d'),
            $transaction->project->name ?? '-',
            $transaction->bankAccount->label ?? '-',
            $transaction->type->value,
            $transaction->kategori?->value ?? '-',
            $transaction->description,
            (float) $transaction->amount,
        ];
    }
}
