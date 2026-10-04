@php
    $statusLabels = \App\Services\PerformanceReviewService::STATUS_LABELS;
    $fmt = fn ($value) => $value === null ? '—' : number_format((float) $value, 2, ',', '.');
    $weights = $review['weights'];
    $effective = $review['effective_weights'];
    $summary = $review['discipline_summary'] ?? [];
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Evaluasi Kinerja — {{ $employee->name }} — {{ $review['period_label'] }}</title>
    <style>
        /* DomPDF renders a subset of CSS 2.1 — plain block/table layout only, no flexbox/grid. */
        body { font-family: sans-serif; font-size: 12px; color: #1a1a1a; }
        h1 { font-size: 18px; margin: 0 0 4px; color: #1a1a1a; }
        h2 { font-size: 15px; margin: 0 0 4px; }
        h3 { font-size: 13px; margin: 20px 0 0; }
        .muted { color: #666; }
        .header { margin-bottom: 24px; border-bottom: 2px solid #F5C518; padding-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
        th { background-color: #FFF6D9; }
        .text-right { text-align: right; }
        .total-row td { font-weight: bold; background-color: #f5f5f5; }
        .meta { margin-top: 12px; width: 100%; }
        .meta td { border: none; padding: 2px 0; }
        .grade { font-size: 22px; font-weight: bold; }
        .box { border: 1px solid #ddd; padding: 8px; margin-top: 8px; }
        .signatures { margin-top: 32px; width: 100%; }
        .signatures td { border: none; width: 33%; vertical-align: top; padding: 0 8px 0 0; }
        .footer { margin-top: 32px; font-size: 10px; color: #999; }
    </style>
</head>
<body>
    <div class="header">
        @if($logo = $siteSettings->logoDataUri())
            <img src="{{ $logo }}" alt="" style="height: 40px; margin-bottom: 8px;">
        @endif
        <h1>{{ $siteSettings->site_name ?? 'Daiku Interior' }}</h1>
        <p class="muted">
            @if($siteSettings->company_address) {{ $siteSettings->company_address }}<br>@endif
            @if($siteSettings->company_phone) {{ $siteSettings->company_phone }} @endif
            @if($siteSettings->company_email) · {{ $siteSettings->company_email }} @endif
        </p>
    </div>

    <h2>Evaluasi Kinerja Karyawan</h2>

    <table class="meta">
        <tr>
            <td style="width: 130px;"><strong>Nama</strong></td>
            <td>: {{ $employee->name }}</td>
        </tr>
        <tr>
            <td><strong>Jabatan</strong></td>
            <td>: {{ $employee->position?->name ?? '—' }}@if($employee->position?->division) ({{ $employee->position->division->name }})@endif</td>
        </tr>
        <tr>
            <td><strong>Periode</strong></td>
            <td>: {{ $review['period_label'] }} ({{ $review['semester'] === 1 ? 'Januari–Juni' : 'Juli–Desember' }})</td>
        </tr>
        <tr>
            <td><strong>Status</strong></td>
            <td>: {{ $statusLabels[$review['status']] ?? $review['status'] }}</td>
        </tr>
        <tr>
            <td><strong>Tanggal Cetak</strong></td>
            <td>: {{ now()->translatedFormat('d F Y') }}</td>
        </tr>
    </table>

    <h3>Rekap Nilai</h3>
    <table>
        <thead>
            <tr>
                <th>Komponen</th>
                <th style="width: 90px;" class="text-right">Nilai</th>
                <th style="width: 90px;" class="text-right">Bobot</th>
                <th style="width: 90px;" class="text-right">Kontribusi</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    KPI (rata-rata {{ $review['kpi_months'] }} bulan yang sudah ditutup)
                    @if($review['kpi_average'] === null)
                        <br><span class="muted">Belum ada periode KPI yang ditutup — bobot KPI dialihkan ke komponen lain.</span>
                    @elseif($review['kpi_capped'])
                        <br><span class="muted">Dihitung maksimal 100 untuk nilai akhir.</span>
                    @endif
                </td>
                <td class="text-right">{{ $fmt($review['kpi_average']) }}</td>
                <td class="text-right">{{ $weights['kpi'] }}%</td>
                <td class="text-right">{{ $review['kpi_average'] === null || ! $effective ? '—' : $fmt(min($review['kpi_average'], 100) * $effective['kpi'] / 100) }}</td>
            </tr>
            <tr>
                <td>Kualitatif</td>
                <td class="text-right">{{ $fmt($review['qualitative_score']) }}</td>
                <td class="text-right">{{ $weights['qualitative'] }}%@if($review['kpi_redistributed'] && $effective) <span class="muted">→ {{ $fmt($effective['qualitative']) }}%</span>@endif</td>
                <td class="text-right">{{ $review['qualitative_score'] === null || ! $effective ? '—' : $fmt($review['qualitative_score'] * $effective['qualitative'] / 100) }}</td>
            </tr>
            <tr>
                <td>Kedisiplinan</td>
                <td class="text-right">{{ $fmt($review['discipline_score']) }}</td>
                <td class="text-right">{{ $weights['discipline'] }}%@if($review['kpi_redistributed'] && $effective) <span class="muted">→ {{ $fmt($effective['discipline']) }}%</span>@endif</td>
                <td class="text-right">{{ $review['discipline_score'] === null || ! $effective ? '—' : $fmt($review['discipline_score'] * $effective['discipline'] / 100) }}</td>
            </tr>
            <tr class="total-row">
                <td colspan="3" class="text-right">NILAI AKHIR</td>
                <td class="text-right">{{ $fmt($review['final_score']) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="meta">
        <tr>
            <td style="width: 130px;"><strong>Grade</strong></td>
            <td>: <span class="grade">{{ $review['grade'] ?? '—' }}</span></td>
        </tr>
        <tr>
            <td><strong>Rekomendasi</strong></td>
            <td>: {{ $review['recommendation_label'] ?? '—' }}</td>
        </tr>
    </table>

    <h3>Aspek Kualitatif (skala 1–5)</h3>
    <table>
        <thead>
            <tr>
                @foreach($aspects as $label)
                    <th>{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            <tr>
                @foreach($aspects as $key => $label)
                    <td>{{ $review['qualitative'][$key] ?? '—' }}</td>
                @endforeach
            </tr>
        </tbody>
    </table>

    <h3>Kedisiplinan Selama Semester</h3>
    <table>
        <thead>
            <tr>
                <th>Teguran Lisan</th>
                <th>SP1</th>
                <th>SP2</th>
                <th>SP3</th>
                <th>Catatan</th>
                <th>SP Berlaku di Akhir Semester</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $summary['teguran_lisan'] ?? 0 }}</td>
                <td>{{ $summary['sp1'] ?? 0 }}</td>
                <td>{{ $summary['sp2'] ?? 0 }}</td>
                <td>{{ $summary['sp3'] ?? 0 }}</td>
                <td>{{ $summary['catatan'] ?? 0 }}</td>
                <td>{{ $summary['active_sp_at_end'] ?? 'Tidak ada' }}</td>
            </tr>
        </tbody>
    </table>

    @if($review['notes'])
        <h3>Catatan Penilai</h3>
        <div class="box">{!! nl2br(e($review['notes'])) !!}</div>
    @endif

    <table class="signatures">
        <tr>
            <td>
                <strong>Penilai (SDM)</strong><br>
                {{ $review['reviewer']['name'] ?? '—' }}<br>
                <span class="muted">{{ $review['submitted_at'] ? \Illuminate\Support\Carbon::parse($review['submitted_at'])->translatedFormat('d F Y') : '' }}</span>
            </td>
            <td>
                <strong>Disetujui (CEO)</strong><br>
                {{ $review['approver']['name'] ?? '—' }}<br>
                <span class="muted">{{ $review['approved_at'] ? \Illuminate\Support\Carbon::parse($review['approved_at'])->translatedFormat('d F Y') : '' }}</span>
            </td>
            <td>
                <strong>Karyawan</strong><br>
                {{ $employee->name }}<br>
                <span class="muted">{{ $review['acknowledged_at'] ? 'Dibaca '.\Illuminate\Support\Carbon::parse($review['acknowledged_at'])->translatedFormat('d F Y') : 'Belum dikonfirmasi' }}</span>
            </td>
        </tr>
    </table>

    <p class="footer">
        Rekomendasi bersifat saran dan tidak mengubah gaji secara otomatis. Dokumen ini dihasilkan otomatis oleh sistem {{ $siteSettings->site_name ?? 'Daiku Interior' }} pada {{ now()->translatedFormat('d F Y, H:i') }} WIB.
    </p>
</body>
</html>
