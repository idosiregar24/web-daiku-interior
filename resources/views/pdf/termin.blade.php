<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Invoice Termin #{{ $termin->termin_number }} — {{ $termin->project->name }}</title>
    <style>
        /* DomPDF renders a subset of CSS 2.1 — plain block/table layout only, no flexbox/grid. */
        body { font-family: sans-serif; font-size: 12px; color: #1a1a1a; }
        h1 { font-size: 18px; margin: 0 0 4px; color: #1a1a1a; }
        .muted { color: #666; }
        .header { margin-bottom: 24px; border-bottom: 2px solid #F5C518; padding-bottom: 12px; }
        table.meta { margin-top: 16px; width: 100%; }
        table.meta td { border: none; padding: 3px 0; }
        table.amount { width: 100%; border-collapse: collapse; margin-top: 24px; }
        table.amount th, table.amount td { border: 1px solid #ddd; padding: 8px 10px; text-align: left; }
        table.amount th { background-color: #FFF6D9; }
        .text-right { text-align: right; }
        .total-row td { font-weight: bold; background-color: #f5f5f5; font-size: 14px; }
        .footer { margin-top: 32px; font-size: 10px; color: #999; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Daiku Interior</h1>
        <p class="muted">Invoice Pembayaran Termin</p>
    </div>

    <table class="meta">
        <tr>
            <td style="width: 140px;"><strong>Proyek</strong></td>
            <td>: {{ $termin->project->name }}</td>
        </tr>
        <tr>
            <td><strong>Klien</strong></td>
            <td>: {{ $termin->project->lead->client_name ?? '—' }}</td>
        </tr>
        <tr>
            <td><strong>Termin ke-</strong></td>
            <td>: {{ $termin->termin_number }}</td>
        </tr>
        @if($termin->milestone)
        <tr>
            <td><strong>Milestone</strong></td>
            <td>: {{ $termin->milestone->name }}</td>
        </tr>
        @endif
        <tr>
            <td><strong>Jadwal (Sabtu)</strong></td>
            <td>: {{ \Illuminate\Support\Carbon::parse($termin->scheduled_date)->translatedFormat('d F Y') }}</td>
        </tr>
        <tr>
            <td><strong>Status</strong></td>
            <td>: {{ $termin->status->value }}</td>
        </tr>
        <tr>
            <td><strong>Tanggal Cetak</strong></td>
            <td>: {{ now()->translatedFormat('d F Y') }}</td>
        </tr>
    </table>

    <table class="amount">
        <thead>
            <tr>
                <th>Keterangan</th>
                <th style="width: 100px;" class="text-right">Persentase</th>
                <th style="width: 160px;" class="text-right">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Pembayaran Termin #{{ $termin->termin_number }} — {{ $termin->project->name }}</td>
                <td class="text-right">{{ $termin->percentage }}%</td>
                <td class="text-right">Rp {{ number_format($termin->amount, 0, ',', '.') }}</td>
            </tr>
            <tr class="total-row">
                <td colspan="2" class="text-right">TOTAL</td>
                <td class="text-right">Rp {{ number_format($termin->amount, 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    <p class="footer">
        Dokumen ini dihasilkan otomatis oleh sistem Daiku Interior pada {{ now()->translatedFormat('d F Y, H:i') }} WIB.
    </p>
</body>
</html>
