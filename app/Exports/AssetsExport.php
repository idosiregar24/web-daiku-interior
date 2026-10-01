<?php

namespace App\Exports;

use App\Models\Asset;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/** PRD §4.8 "Export daftar ... aset ke Excel", incl. the §4.7 installment plan. */
class AssetsExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    private const CONDITION_LABELS = ['GOOD' => 'Baik', 'FAIR' => 'Cukup', 'DAMAGED' => 'Rusak'];

    public function collection(): Collection
    {
        return Asset::query()->orderBy('category')->orderBy('name')->get();
    }

    public function headings(): array
    {
        return [
            'Nama', 'Kategori', 'Tanggal Beli', 'Nilai', 'Kondisi', 'Lokasi', 'Catatan',
            'Cicilan', 'Total Cicilan', 'Terbayar', 'Sisa Cicilan', 'Cicilan per Bulan', 'Jatuh Tempo (Tgl)',
        ];
    }

    public function map($asset): array
    {
        $plan = $asset->has_installment
            ? [
                'Ya',
                (float) $asset->total_install,
                (float) $asset->paid_install,
                (float) $asset->remaining_install,
                $asset->installment_amount !== null ? (float) $asset->installment_amount : '-',
                $asset->installment_due_day ?? '-',
            ]
            : ['Tidak', '-', '-', '-', '-', '-'];

        return [
            $asset->name,
            $asset->category ?? '-',
            $asset->purchase_date?->format('Y-m-d') ?? '-',
            (float) $asset->value,
            self::CONDITION_LABELS[$asset->condition->value],
            $asset->location ?? '-',
            $asset->notes ?? '',
            ...$plan,
        ];
    }

    public function title(): string
    {
        return 'Aset';
    }
}
