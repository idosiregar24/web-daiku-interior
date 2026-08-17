<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Penawaran — {{ $quotation->lead->client_name }}</title>
    <style>
        /* DomPDF renders a subset of CSS 2.1 — plain block/table layout only, no flexbox/grid. */
        body { font-family: sans-serif; font-size: 12px; color: #1a1a1a; }
        h1 { font-size: 18px; margin: 0 0 4px; color: #1a1a1a; }
        .muted { color: #666; }
        .header { margin-bottom: 24px; border-bottom: 2px solid #F5C518; padding-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
        th { background-color: #FFF6D9; }
        .text-right { text-align: right; }
        .total-row td { font-weight: bold; background-color: #f5f5f5; }
        .meta { margin-top: 16px; width: 100%; }
        .meta td { border: none; padding: 2px 0; }
        .footer { margin-top: 32px; font-size: 10px; color: #999; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $siteSettings->site_name ?? 'Daiku Interior' }}</h1>
        <p class="muted">
            @if($siteSettings->company_address) {{ $siteSettings->company_address }}<br>@endif
            @if($siteSettings->company_phone) {{ $siteSettings->company_phone }} @endif
            @if($siteSettings->company_email) · {{ $siteSettings->company_email }} @endif
        </p>
    </div>

    <h2>Surat Penawaran (RAB)</h2>

    <table class="meta">
        <tr>
            <td style="width: 120px;"><strong>Klien</strong></td>
            <td>: {{ $quotation->lead->client_name }}</td>
        </tr>
        <tr>
            <td><strong>Nomor</strong></td>
            <td>: QUO-{{ str_pad($quotation->id, 5, '0', STR_PAD_LEFT) }} (versi {{ $quotation->version }})</td>
        </tr>
        <tr>
            <td><strong>Status</strong></td>
            <td>: {{ $quotation->status->value }}</td>
        </tr>
        <tr>
            <td><strong>Tanggal Cetak</strong></td>
            <td>: {{ now()->translatedFormat('d F Y') }}</td>
        </tr>
        @if($quotation->valid_until)
        <tr>
            <td><strong>Berlaku Sampai</strong></td>
            <td>: {{ \Illuminate\Support\Carbon::parse($quotation->valid_until)->translatedFormat('d F Y') }}</td>
        </tr>
        @endif
    </table>

    <table>
        <thead>
            <tr>
                <th style="width: 30px;">No</th>
                <th>Deskripsi</th>
                <th style="width: 50px;">Qty</th>
                <th style="width: 60px;">Satuan</th>
                <th style="width: 100px;" class="text-right">Harga Satuan</th>
                <th style="width: 110px;" class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($quotation->items as $index => $item)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $item->description }}</td>
                <td>{{ $item->qty }}</td>
                <td>{{ $item->unit }}</td>
                <td class="text-right">{{ number_format($item->unit_price, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($item->total_price, 0, ',', '.') }}</td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="5" class="text-right">TOTAL</td>
                <td class="text-right">Rp {{ number_format($quotation->total_amount, 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    <p class="footer">
        Dokumen ini dihasilkan otomatis oleh sistem {{ $siteSettings->site_name ?? 'Daiku Interior' }} pada {{ now()->translatedFormat('d F Y, H:i') }} WIB.
    </p>
</body>
</html>
