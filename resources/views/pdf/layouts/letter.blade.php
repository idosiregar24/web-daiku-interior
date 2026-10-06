{{--
    Sprint 15 — the company letter (offer / invoice) in the format the user
    handed over. Fed by App\Support\Letters\{QuotationLetter, InvoiceLetter}
    ($letter); the client's link page renders the same array.
    DomPDF renders a subset of CSS 2.1: tables and fixed boxes only, no flexbox.
--}}
@php
    $rp = fn ($amount) => number_format((float) $amount, 0, ',', '.');
    $company = $letter['company'];
    $signer = $letter['signer'];
    $title = $letter['kind'] === 'INVOICE' ? 'Invoice' : 'Penawaran';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} {{ $letter['number'] ?? '' }} — {{ $letter['recipient'] }}</title>
    <style>
        @page { margin: 125px 56px 70px 56px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1a1a1a; line-height: 1.45; }

        .letterhead { position: fixed; top: -125px; left: -56px; right: -56px; height: 104px; }
        .letterhead table { width: 100%; border-collapse: collapse; }
        .letterhead td { vertical-align: middle; padding: 0; }
        .letterhead .brand { padding-left: 56px; height: 96px; }
        .letterhead .brand img { height: 58px; }
        .letterhead .brand .name { font-size: 20px; font-weight: bold; }
        .letterhead .contact { width: 46%; background: #f5c518; text-align: right; padding: 10px 56px 10px 16px; font-size: 9.5px; color: #1a1a1a; }
        .letterhead .contact .line { height: 17px; line-height: 17px; }
        .letterhead .contact .icon { width: 11px; height: 11px; margin-left: 5px; vertical-align: middle; }
        .letterhead .rule { height: 6px; background: #1a1a1a; }

        .footer { position: fixed; bottom: -70px; left: -56px; right: -56px; height: 22px; background: #1a1a1a; color: #ffffff;
            text-align: center; font-size: 9px; font-weight: bold; letter-spacing: 0.4px; padding-top: 8px; }

        .watermark { position: fixed; top: 210px; left: 90px; width: 420px; opacity: 0.06; }

        .date { text-align: right; margin: 0 0 14px; }
        .meta { border-collapse: collapse; margin-bottom: 14px; }
        .meta td { padding: 1px 0; vertical-align: top; }
        .meta .label { width: 100px; white-space: nowrap; }
        .draft { color: #b42318; font-weight: bold; }
        p { margin: 0 0 8px; }

        table.rab { width: 100%; border-collapse: collapse; margin: 6px 0 4px; font-size: 9px; }
        table.rab th, table.rab td { border: 1px solid #1a1a1a; padding: 3px 5px; }
        table.rab th { background: #c9d9f0; font-weight: bold; text-align: center; }
        table.rab td.num { text-align: right; white-space: nowrap; }
        table.rab td.center { text-align: center; }
        table.rab tr.group td { font-weight: bold; background: #f2f5fa; }
        table.rab tr.subtotal td { font-style: italic; }
        table.rab tr.total td { background: #c9d9f0; font-weight: bold; }
        .words { font-weight: bold; margin: 2px 0 14px; }

        table.terms { width: 100%; border-collapse: collapse; margin: 4px 0 12px; font-size: 9px; }
        table.terms th, table.terms td { border: 1px solid #1a1a1a; padding: 3px 5px; }
        table.terms th { background: #c9d9f0; }

        ol.notes { margin: 2px 0 14px 0; padding-left: 18px; }
        ol.notes li { margin-bottom: 3px; }

        .closing { font-size: 9.5px; margin-top: 10px; }
        .sign { width: 100%; margin-top: 18px; }
        .sign td { vertical-align: top; }
        .sign .slot { width: 210px; }
        .sign .signature { height: 64px; margin: 4px 0 2px; }
        .sign .name { font-weight: bold; }
        .stamp { display: inline-block; border: 2px solid #067647; color: #067647; font-weight: bold; padding: 2px 10px; letter-spacing: 2px; }
    </style>
</head>
<body>
    <div class="letterhead">
        <table>
            <tr>
                <td class="brand">
                    @if($company['logo'])
                        <img src="{{ $company['logo'] }}" alt="">
                    @else
                        <span class="name">{{ $company['name'] }}</span>
                    @endif
                </td>
                <td class="contact">
                    {{-- Text, then its icon — as on the company's letter. --}}
                    @foreach(['address', 'email', 'phone', 'instagram'] as $field)
                        @if($company[$field])
                            <div class="line">{{ $company[$field] }} <img class="icon" src="{{ $company['icons'][$field] }}" alt=""></div>
                        @endif
                    @endforeach
                </td>
            </tr>
        </table>
        <div class="rule"></div>
    </div>

    <div class="footer">{{ $company['footer'] }}</div>

    @if($company['logo'])
        <img class="watermark" src="{{ $company['logo'] }}" alt="">
    @endif

    <p class="date">{{ $letter['date'] }}</p>

    <table class="meta">
        <tr>
            <td class="label">Nomor</td>
            <td>:
                @if($letter['draft'])
                    <span class="draft">DRAF</span> — nomor diterbitkan saat penawaran dikirim ke klien
                @else
                    {{ $letter['number'] }}
                @endif
            </td>
        </tr>
        <tr>
            <td class="label">Perihal</td>
            <td>: {{ $letter['subject'] }}</td>
        </tr>
        @foreach($letter['meta'] as $meta)
        <tr>
            <td class="label">{{ $meta['label'] }}</td>
            <td>: {{ $meta['value'] }}</td>
        </tr>
        @endforeach
    </table>

    <p>Kepada,<br><strong>{{ $letter['recipient'] }}</strong></p>

    <p style="margin-top: 14px;">Dengan hormat,<br>{{ $letter['intro'] }}</p>

    <table class="rab">
        <thead>
            <tr>
                <th rowspan="2" style="width: 22px;">NO</th>
                <th rowspan="2">URAIAN PEKERJAAN</th>
                <th colspan="2">UKURAN</th>
                <th rowspan="2" style="width: 46px;">VOLUME</th>
                <th rowspan="2" style="width: 44px;">SATUAN</th>
                <th style="width: 72px;">SATUAN</th>
                <th style="width: 78px;">HARGA</th>
            </tr>
            <tr>
                <th style="width: 34px;">P</th>
                <th style="width: 34px;">T</th>
                <th>(Rp)</th>
                <th>(Rp)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($letter['groups'] as $group)
                @if($letter['showGroups'])
                <tr class="group">
                    <td class="center">{{ $group['label'] }}</td>
                    <td colspan="7">{{ mb_strtoupper($group['name']) }}</td>
                </tr>
                @endif
                @foreach($group['rows'] as $row)
                <tr>
                    <td class="center">{{ $row['no'] }}</td>
                    <td>{{ mb_strtoupper($row['description']) }}</td>
                    <td class="center">{{ $row['p'] }}</td>
                    <td class="center">{{ $row['t'] }}</td>
                    <td class="center">{{ $row['volume'] }}</td>
                    <td class="center">{{ $row['unit'] }}</td>
                    <td class="num">{{ $rp($row['unitPrice']) }}</td>
                    <td class="num">{{ $rp($row['total']) }}</td>
                </tr>
                @endforeach
                @if($letter['showGroups'])
                <tr class="subtotal">
                    <td colspan="7" class="num">Subtotal {{ $group['name'] }}</td>
                    <td class="num">{{ $rp($group['subtotal']) }}</td>
                </tr>
                @endif
            @endforeach
            @foreach($letter['totals'] as $total)
            <tr class="total">
                <td colspan="7" class="num">{{ $total['label'] }}</td>
                <td class="num">{{ $total['amount'] < 0 ? '- '.$rp(-$total['amount']) : $rp($total['amount']) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <p class="words">TERBILANG: {{ $letter['totalInWords'] }}</p>

    @if(count($letter['paymentTerms']) > 0)
    <p style="margin-bottom: 2px;"><strong>Skema Pembayaran</strong></p>
    <table class="terms">
        <thead>
            <tr>
                <th style="width: 22px;">NO</th>
                <th>TERMIN</th>
                <th style="width: 40px;">%</th>
                <th style="width: 90px;">NOMINAL (Rp)</th>
                <th>WAKTU PEMBAYARAN</th>
            </tr>
        </thead>
        <tbody>
            @foreach($letter['paymentTerms'] as $term)
            <tr>
                <td style="text-align: center;">{{ $term['sequence'] }}</td>
                <td>{{ $term['label'] }}</td>
                <td style="text-align: right;">{{ $term['percentage'] }}%</td>
                <td style="text-align: right;">{{ $rp($term['amount']) }}</td>
                <td>{{ $term['when'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <p style="margin-bottom: 2px;">Catatan:</p>
    <ol class="notes">
        @foreach($letter['notes'] as $note)
            <li>{{ $note }}</li>
        @endforeach
    </ol>

    @if($letter['stamp'])
        <p><span class="stamp">{{ $letter['stamp'] }}</span></p>
    @endif

    <p class="closing">{{ $letter['closing'] }}</p>

    <table class="sign">
        <tr>
            <td></td>
            <td class="slot">
                Hormat kami,<br>
                @if($signer['signature'])
                    <img class="signature" src="{{ $signer['signature'] }}" alt=""><br>
                @elseif($company['logo'])
                    <img class="signature" src="{{ $company['logo'] }}" alt="" style="height: 40px; margin: 14px 0 8px;"><br>
                @else
                    <br><br><br>
                @endif
                <span class="name">{{ $signer['name'] ?: $company['name'] }}</span>
                @if($signer['title'])<br>{{ $signer['title'] }}@endif
            </td>
        </tr>
    </table>
</body>
</html>
