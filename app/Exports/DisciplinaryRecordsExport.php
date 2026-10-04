<?php

namespace App\Exports;

use App\Models\DisciplinaryRecord;
use App\Services\DisciplineService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * SDM (Sprint 10, §3.1) "export Excel" of the Kedisiplinan recap — the same
 * filters as the page (DisciplineService::recapQuery()). User-typed text
 * goes through SafeValueBinder (config/excel.php), never as a formula.
 */
class DisciplinaryRecordsExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(private array $filters = []) {}

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return $this->filters;
    }

    public function collection(): Collection
    {
        return app(DisciplineService::class)->recapQuery($this->filters)->get();
    }

    public function headings(): array
    {
        return ['Tanggal Terbit', 'Karyawan', 'Divisi', 'Jabatan', 'Jenis', 'Berlaku Sampai', 'Uraian', 'Link Dokumen', 'Status', 'Dicatat Oleh'];
    }

    public function map($record): array
    {
        /** @var DisciplinaryRecord $record */
        $status = match (true) {
            $record->voidedBy !== null => 'Dibatalkan: '.$record->voidedBy->description,
            $record->voids !== null => 'Membatalkan '.$record->voids->type->label().' '.$record->voids->issued_on->format('d/m/Y'),
            $record->type->spLevel() !== null && $record->valid_until?->gte(today()) && $record->issued_on->lte(today()) => 'Berlaku',
            $record->type->spLevel() !== null => 'Kedaluwarsa',
            default => '-',
        };

        return [
            $record->issued_on->format('d/m/Y'),
            $record->employee?->name ?? '-',
            $record->employee?->position?->division?->name ?? '-',
            $record->employee?->position?->name ?? '-',
            $record->type->label(),
            $record->valid_until?->format('d/m/Y') ?? '-',
            $record->description,
            $record->link ?? '-',
            $status,
            $record->recorder?->name ?? '-',
        ];
    }

    public function title(): string
    {
        return 'Kedisiplinan';
    }
}
