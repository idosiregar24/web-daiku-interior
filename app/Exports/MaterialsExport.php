<?php

namespace App\Exports;

use App\Models\Material;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/** PRD §4.8 "Export daftar material ... ke Excel" — full master with margin and stock status. */
class MaterialsExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    public function collection(): Collection
    {
        return Material::query()->orderBy('category')->orderBy('name')->get();
    }

    public function headings(): array
    {
        return ['Nama', 'Kategori', 'Satuan', 'Harga Modal', 'Harga Jual', 'Margin', 'Margin (%)', 'Stok', 'Stok Minimum', 'Status Stok'];
    }

    public function map($material): array
    {
        return [
            $material->name,
            $material->category ?? '-',
            $material->unit,
            (float) $material->cost_price,
            (float) $material->sell_price,
            $material->margin,
            $material->margin_percent,
            $material->stock,
            $material->min_stock,
            $material->is_low_stock ? 'Di bawah minimum' : 'Aman',
        ];
    }

    public function title(): string
    {
        return 'Material';
    }
}
