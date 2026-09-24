<?php

namespace App\Exports;

use App\Models\Asset;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/** PRD §4.8 "Export daftar ... aset ke Excel". */
class AssetsExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    private const CONDITION_LABELS = ['GOOD' => 'Baik', 'FAIR' => 'Cukup', 'DAMAGED' => 'Rusak'];

    public function collection(): Collection
    {
        return Asset::query()->orderBy('category')->orderBy('name')->get();
    }

    public function headings(): array
    {
        return ['Nama', 'Kategori', 'Tanggal Beli', 'Nilai', 'Kondisi', 'Lokasi', 'Catatan'];
    }

    public function map($asset): array
    {
        return [
            $asset->name,
            $asset->category ?? '-',
            $asset->purchase_date?->format('Y-m-d') ?? '-',
            (float) $asset->value,
            self::CONDITION_LABELS[$asset->condition->value],
            $asset->location ?? '-',
            $asset->notes ?? '',
        ];
    }

    public function title(): string
    {
        return 'Aset';
    }
}
