<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->number }} — {{ $invoice->lead->client_name }}</title>
    <style>
        /* DomPDF renders a subset of CSS 2.1 — plain block/table layout only, no flexbox/grid. */
        body { font-family: sans-serif; font-size: 12px; color: #1a1a1a; }
        h1 { font-size: 18px; margin: 0 0 4px; color: #1a1a1a; }
        h2 { font-size: 15px; margin: 0 0 4px; }
        .muted { color: #666; }
        .header { margin-bottom: 24px; border-bottom: 2px solid #F5C518; padding-bottom: 12px; }
        table.meta { margin-top: 16px; width: 100%; }
        table.meta td { border: none; padding: 3px 0; vertical-align: top; }
        table.amount { width: 100%; border-collapse: collapse; margin-top: 24px; }
        table.amount th, table.amount td { border: 1px solid #ddd; padding: 8px 10px; text-align: left; }
        table.amount th { background-color: #FFF6D9; }
        .text-right { text-align: right; }
        .total-row td { font-weight: bold; background-color: #f5f5f5; font-size: 14px; }
        .paid { margin-top: 16px; padding: 8px 10px; border: 1px solid #16a34a; color: #166534; }
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

    <h2>INVOICE {{ $invoice->number }}</h2>

    <table class="meta">
        <tr>
            <td style="width: 140px;"><strong>Kepada</strong></td>
            <td>: {{ $invoice->lead->client_name }}@if($invoice->lead->address)<br>&nbsp;&nbsp;{{ $invoice->lead->address }}@endif</td>
        </tr>
        <tr>
            <td><strong>Tanggal Terbit</strong></td>
            <td>: {{ $invoice->issued_at->translatedFormat('d F Y') }}</td>
        </tr>
        <tr>
            <td><strong>Jatuh Tempo</strong></td>
            <td>: {{ $invoice->due_date->translatedFormat('d F Y') }}</td>
        </tr>
        @if($invoice->quotation)
        <tr>
            <td><strong>Referensi</strong></td>
            <td>: {{ $invoice->quotation->type->label() }} QUO-{{ str_pad($invoice->quotation->id, 5, '0', STR_PAD_LEFT) }} versi {{ $invoice->quotation->version }}</td>
        </tr>
        @endif
    </table>

    <table class="amount">
        <thead>
            <tr>
                <th>Keterangan</th>
                <th style="width: 180px;" class="text-right">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Pembayaran {{ $invoice->type->label() }}</td>
                <td class="text-right">Rp {{ number_format($invoice->amount, 0, ',', '.') }}</td>
            </tr>
            <tr class="total-row">
                <td class="text-right">TOTAL TAGIHAN</td>
                <td class="text-right">Rp {{ number_format($invoice->amount, 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    @if($invoice->status === \App\Enums\InvoiceStatus::Terverifikasi)
        <p class="paid">LUNAS — pembayaran diterima {{ $invoice->paid_date?->translatedFormat('d F Y') }}.</p>
    @elseif($bankAccounts->isNotEmpty())
        <p style="margin-top: 16px;"><strong>Pembayaran ke rekening:</strong></p>
        <table class="meta">
            @foreach($bankAccounts as $account)
            <tr>
                <td style="width: 140px;">{{ $account->bank_name }}</td>
                <td>: {{ $account->account_no }} ({{ $account->label }})</td>
            </tr>
            @endforeach
        </table>
    @endif

    <p class="footer">
        Dokumen ini dihasilkan otomatis oleh sistem {{ $siteSettings->site_name ?? 'Daiku Interior' }} pada {{ now()->translatedFormat('d F Y, H:i') }} WIB.
    </p>
</body>
</html>
