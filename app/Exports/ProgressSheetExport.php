<?php

namespace App\Exports;

use App\Models\Project;
use App\Models\SiteSetting;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The Lembar Progres (Detail Proyek › Item RAB) as a workbook, laid out like
 * the SIMULASI PERSENTASI sheet it replaced: No · Uraian · Volume · Kepala
 * Tim · Jadwal · Nilai · Bobot, the % added on each pengecekan date, then
 * Total % · (Daily Target Selesai) · Kontribusi, and the "Jumlah" /
 * "Progres kumulatif" rows. Figures come from WorkItemService only
 * (progressRecap() + scheduleOverview()) — nothing is computed here. Item
 * text goes through SafeValueBinder (config/excel.php).
 */
class ProgressSheetExport implements FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    /** Columns before the first pengecekan date: No, Uraian, Volume, Kepala Tim, Jadwal, Nilai, Bobot. */
    private const LEAD_COLUMNS = 7;

    private int $headingRow = 0;

    private int $lastRow = 0;

    private bool $withTarget;

    /**
     * @param  array<string, mixed>  $recap  WorkItemService::progressRecap()
     * @param  array<string, mixed>  $schedule  WorkItemService::scheduleOverview()
     */
    public function __construct(private Project $project, private array $recap, private array $schedule)
    {
        $this->withTarget = $recap['target_total'] !== null;
    }

    public function array(): array
    {
        $recap = $this->recap;
        $dates = $recap['dates'];
        $windows = collect($this->schedule['items'] ?? [])->keyBy('id');
        $perDate = collect($recap['per_date'])->keyBy('date');
        $site = SiteSetting::current();

        $rows = [
            [$site->site_name ?? 'Daiku Interior'],
            ["Lembar Progres — {$this->project->name}"],
            ['Klien', $this->project->lead?->client_name ?? '—'],
            ['Jadwal Proyek', $this->range($this->project->start_date?->toDateString(), $this->project->end_date?->toDateString(), 'Belum ditentukan')],
            ['Progres Berbobot', $recap['total'] ?? '—'],
            ['Tanggal Cetak', now('Asia/Jakarta')->locale('id')->translatedFormat('d F Y, H.i')],
            // [''] not [] — an empty array is dropped by FromArray and every row number in styles() would shift by one.
            [''],
            [
                'No', 'Uraian', 'Volume', 'Kepala Tim', 'Jadwal', 'Nilai (Rp)', 'Bobot (%)',
                ...array_map(fn (string $date) => Carbon::parse($date)->locale('id')->translatedFormat('d M Y'), $dates),
                ...$this->tail('Total % (cek Pengawas)', 'Daily Target Selesai (%)', 'Kontribusi (%)'),
            ],
        ];
        $this->headingRow = count($rows);

        foreach ($recap['items'] as $index => $item) {
            $cells = (array) $item['cells'];
            $timelines = collect($windows->get($item['id'])['timelines'] ?? []);

            $rows[] = [
                $index + 1,
                $item['description'],
                $item['volume_label'],
                $item['team_lead'],
                // The item's schedule is its Timelines (Sprint 35): first start → last end.
                $this->range($timelines->pluck('start')->filter()->min(), $timelines->pluck('end')->filter()->max(), 'Belum dijadwalkan'),
                $item['value'],
                $item['weight'],
                ...array_map(fn (string $date) => $cells[$date] ?? null, $dates),
                ...$this->tail($item['total'], $item['target']['percent'] ?? null, $item['contribution']),
            ];
        }

        $rows[] = [
            '', 'Jumlah', '', '', '',
            collect($recap['items'])->sum('value'),
            collect($recap['items'])->sum('weight'),
            ...array_map(fn (string $date) => $perDate[$date]['added'] ?? null, $dates),
            ...$this->tail(null, $recap['target_total'], $recap['total']),
        ];
        $rows[] = [
            '', 'Progres kumulatif', '', '', '', null, null,
            ...array_map(fn (string $date) => $perDate[$date]['cumulative'] ?? null, $dates),
            ...$this->tail(null, null, null),
        ];
        $this->lastRow = count($rows);

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        $last = Coordinate::stringFromColumnIndex(self::LEAD_COLUMNS + count($this->recap['dates']) + ($this->withTarget ? 3 : 2));
        $first = $this->headingRow + 1;

        $sheet->getStyle('B5')->getNumberFormat()->setFormatCode('0.00"%"');
        $sheet->getStyle("F{$first}:F{$this->lastRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("G{$first}:{$last}{$this->lastRow}")->getNumberFormat()->setFormatCode('0.00');
        $sheet->getStyle("A{$this->headingRow}:{$last}{$this->lastRow}")->getBorders()->getAllBorders()->setBorderStyle('thin');
        // No + Uraian and the heading stay in view while the dates scroll, like the sheet's frozen panes.
        $sheet->freezePane('C'.$first);

        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            2 => ['font' => ['bold' => true, 'size' => 12]],
            $this->headingRow => [
                'font' => ['bold' => true],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FFF6D9']],
            ],
            $this->lastRow - 1 => ['font' => ['bold' => true]],
            $this->lastRow => ['font' => ['italic' => true]],
        ];
    }

    public function title(): string
    {
        return 'Lembar Progres';
    }

    /**
     * Total % · Daily Target Selesai (only when some item has daily targets) · Kontribusi.
     *
     * @return list<mixed>
     */
    private function tail(mixed $total, mixed $target, mixed $contribution): array
    {
        return $this->withTarget ? [$total, $target, $contribution] : [$total, $contribution];
    }

    /** "5 Okt 2026 – 20 Okt 2026", one date when both are the same, `$none` without any. */
    private function range(?string $start, ?string $end, string $none): string
    {
        if ($start === null && $end === null) {
            return $none;
        }

        $format = fn (?string $date) => $date ? Carbon::parse($date)->locale('id')->translatedFormat('d M Y') : '…';

        return $start === $end ? $format($start) : $format($start).' – '.$format($end);
    }
}
