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
        // Working catalog only — merged items are inactive records (Sprint 11 §5.5).
        return Material::query()->with('category:id,name')->active()->orderBy('code')->get();
    }

    public function headings(): array
    {
        return ['Kode', 'Nama', 'Kategori', 'Satuan', 'Harga Modal', 'Harga Jual', 'Margin', 'Margin (%)', 'Stok', 'Stok Minimum', 'Status Stok'];
    }

    public function map($material): array
    {
        return [
            $material->code,
            $material->name,
            $material->category?->name ?? '-',
            $material->unit?->code,
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
