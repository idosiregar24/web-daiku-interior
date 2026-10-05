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
        .section-row td { font-weight: bold; background-color: #fafafa; }
        .subtotal-row td { font-style: italic; }
        .meta { margin-top: 16px; width: 100%; }
        .meta td { border: none; padding: 2px 0; }
        .validity { margin-top: 16px; }
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

    <h2>Surat Penawaran — {{ $quotation->type?->label() ?? 'RAB Proyek' }}</h2>

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
        <tr>
            <td><strong>Berlaku Sampai</strong></td>
            {{-- Set when CEO & PM approval sends the offer (QuotationService::VALIDITY_DAYS); a draft only states the rule. --}}
            @if($quotation->valid_until)
            <td>: {{ $quotation->valid_until->translatedFormat('d F Y') }}</td>
            @else
            <td>: {{ $validityDays }} hari sejak penawaran dikirim</td>
            @endif
        </tr>
    </table>

    {{-- Sprint 12 #11 — per bagian pekerjaan (items without one: "Umum"), dimensions, then Total / Diskon / Pembulatan. --}}
    <table>
        <thead>
            <tr>
                <th style="width: 28px;">No</th>
                <th>Item</th>
                <th style="width: 40px;" class="text-right">P</th>
                <th style="width: 40px;" class="text-right">T/L</th>
                <th style="width: 45px;" class="text-right">Volume</th>
                <th style="width: 45px;">Satuan</th>
                <th style="width: 85px;" class="text-right">Harga</th>
                <th style="width: 95px;" class="text-right">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($quotation->rabGroups()->values() as $groupIndex => $group)
            <tr class="section-row">
                <td>{{ $groupIndex < 26 ? chr(65 + $groupIndex) : $groupIndex + 1 }}</td>
                <td colspan="7">{{ $group['name'] }}</td>
            </tr>
            @foreach($group['items']->values() as $index => $item)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $item->description }}</td>
                <td class="text-right">{{ $item->dim_length !== null ? \App\Support\Quantity::format($item->dim_length) : '' }}</td>
                <td class="text-right">{{ $item->dim_width_height !== null ? \App\Support\Quantity::format($item->dim_width_height) : '' }}</td>
                <td class="text-right">{{ \App\Support\Quantity::format($item->qty) }}</td>
                <td>{{ $item->unit?->code }}</td>
                <td class="text-right">{{ number_format($item->unit_price, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($item->total_price, 0, ',', '.') }}</td>
            </tr>
            @endforeach
            <tr class="subtotal-row">
                <td colspan="7" class="text-right">Subtotal {{ $group['name'] }}</td>
                <td class="text-right">{{ number_format($group['subtotal'], 0, ',', '.') }}</td>
            </tr>
            @endforeach
            <tr>
                <td colspan="7" class="text-right">Total</td>
                <td class="text-right">Rp {{ number_format($quotation->items_total ?? $quotation->total_amount, 0, ',', '.') }}</td>
            </tr>
            @if((float) $quotation->discount_amount > 0)
            <tr>
                <td colspan="7" class="text-right">Diskon</td>
                <td class="text-right">- Rp {{ number_format($quotation->discount_amount, 0, ',', '.') }}</td>
            </tr>
            @endif
            @if($quotation->rounded_total !== null)
            <tr>
                <td colspan="7" class="text-right">Pembulatan</td>
                <td class="text-right">Rp {{ number_format($quotation->rounded_total, 0, ',', '.') }}</td>
            </tr>
            @endif
            <tr class="total-row">
                <td colspan="7" class="text-right">GRAND TOTAL</td>
                <td class="text-right">Rp {{ number_format($quotation->total_amount, 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    @if($quotation->paymentTerms->isNotEmpty())
    {{-- Sprint 12 #12 — the DP/termin scheme the client approves with the RAB. --}}
    <h3 style="margin-top: 20px;">Skema Pembayaran</h3>
    <table style="margin-top: 6px;">
        <thead>
            <tr>
                <th style="width: 28px;">No</th>
                <th>Termin</th>
                <th style="width: 50px;" class="text-right">%</th>
                <th style="width: 110px;" class="text-right">Nominal</th>
                <th>Pemicu</th>
            </tr>
        </thead>
        <tbody>
            @foreach($quotation->paymentTerms as $term)
            <tr>
                <td>{{ $term->sequence }}</td>
                <td>{{ $term->label }}</td>
                <td class="text-right">{{ rtrim(rtrim(number_format($term->percentage, 2, ',', '.'), '0'), ',') }}%</td>
                <td class="text-right">Rp {{ number_format($term->amount, 0, ',', '.') }}</td>
                <td>
                    {{ $term->trigger->label() }}
                    @if($term->due_date) — {{ $term->due_date->translatedFormat('d F Y') }} @endif
                    @if($term->milestone_name) — {{ $term->milestone_name }} @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    @if($quotation->valid_until)
    <p class="validity">
        Penawaran ini berlaku sampai {{ $quotation->valid_until->translatedFormat('d F Y') }}. Setelah tanggal tersebut, harga dan ketersediaan dapat berubah.
    </p>
    @endif

    <p class="footer">
        Dokumen ini dihasilkan otomatis oleh sistem {{ $siteSettings->site_name ?? 'Daiku Interior' }} pada {{ now()->translatedFormat('d F Y, H:i') }} WIB.
    </p>
</body>
</html>
