{{--
    The invoice in the same company-letter style as the offer (layouts/letter)
    — changed 2026-10-15 at the user's request (Sprint 17 Sub 05 had a separate
    "modern bill" look): date on the right, Nomor / Perihal / invoice data,
    "Ditagihkan kepada", the bordered table with the light-blue header, the
    total row + TERBILANG, "Cara Pembayaran" as a bordered table, numbered
    Catatan, a status stamp and the signature. Same letterhead / footer /
    signature partials. Fed by App\Support\Letters\InvoiceLetter ($letter). PDF only.
    DomPDF renders a subset of CSS 2.1: tables and fixed boxes only, no flexbox.
--}}
@php
    $rp = fn ($amount) => number_format((float) $amount, 0, ',', '.');
    $signed = fn ($amount) => $amount < 0 ? '- '.$rp(-$amount) : $rp($amount);
    $company = $letter['company'];
    $signer = $letter['signer'];
    $billTo = $letter['billTo'];
    $payment = $letter['paymentInfo'];
    // The letter puts the date top-right and the number on its own line, like the offer.
    $date = collect($letter['meta'])->firstWhere('label', 'Tanggal')['value'] ?? null;
    $meta = collect($letter['meta'])->reject(fn ($row) => in_array($row['label'], ['Tanggal', 'No. Invoice'], true))->values();
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $letter['number'] ?? 'DRAF' }} — {{ $letter['recipient'] }}</title>
    <style>
        @include('pdf.layouts.partials.frame-css')

        p { margin: 0 0 8px; }
        .muted { color: #6b6b6b; }

        /* Same frame as the offer letter (layouts/letter). */
        table.top { width: 100%; border-collapse: collapse; margin: 0 0 10px; }
        table.top td { padding: 0; vertical-align: top; }
        .title { font-size: 15px; font-weight: bold; letter-spacing: 2px; }
        .date { text-align: right; }
        .meta { border-collapse: collapse; margin-bottom: 14px; }
        .meta td { padding: 1px 0; vertical-align: top; }
        .meta .label { width: 100px; white-space: nowrap; }
        .draft { color: #b42318; font-weight: bold; }

        table.rab { width: 100%; border-collapse: collapse; margin: 6px 0 4px; font-size: 9px; }
        table.rab th, table.rab td { border: 1px solid #1a1a1a; padding: 3px 5px; }
        table.rab th { background: #c9d9f0; font-weight: bold; text-align: center; }
        table.rab td.num { text-align: right; white-space: nowrap; }
        table.rab td.center { text-align: center; }
        table.rab .detail { color: #6b6b6b; font-size: 8.5px; }
        table.rab tr.adjustment td { font-style: italic; }
        table.rab tr.total td { background: #c9d9f0; font-weight: bold; }
        .words { font-weight: bold; margin: 2px 0 14px; }

        table.terms { width: 100%; border-collapse: collapse; margin: 4px 0 4px; font-size: 9px; }
        table.terms th, table.terms td { border: 1px solid #1a1a1a; padding: 3px 5px; }
        table.terms th { background: #c9d9f0; }
        .instruction { font-weight: bold; margin: 4px 0 14px; }

        ol.notes { margin: 2px 0 14px 0; padding-left: 18px; }
        ol.notes li { margin-bottom: 3px; }

        .closing { font-size: 9.5px; margin-top: 10px; }
        /* The status reads as one line of the Nomor / Perihal table (a boxed stamp overlapped the rows). */
        .stamp { color: #6b6b6b; font-weight: bold; letter-spacing: 1px; }
        .stamp-warning { color: #b54708; }
        .stamp-success { color: #067647; }
    </style>
</head>
<body>
    @include('pdf.layouts.partials.letterhead', ['company' => $company])

    <table class="top">
        <tr>
            <td><div class="title">INVOICE</div></td>
            <td class="date">{{ $date }}</td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td class="label">Nomor</td>
            <td>:
                @if($letter['draft'])
                    <span class="draft">DRAF</span> — nomor terbit saat invoice diterbitkan
                @else
                    {{ $letter['number'] }}
                @endif
            </td>
        </tr>
        <tr>
            <td class="label">Perihal</td>
            <td>: {{ $letter['subject'] }}</td>
        </tr>
        @foreach($meta as $row)
        <tr>
            <td class="label">{{ $row['label'] }}</td>
            <td>: {{ $row['value'] }}</td>
        </tr>
        @endforeach
        <tr>
            <td class="label">Status</td>
            <td>: <span class="stamp stamp-{{ $letter['status']['tone'] }}">{{ $letter['status']['label'] }}</span></td>
        </tr>
    </table>

    <p>Ditagihkan kepada,<br><strong>{{ $billTo['name'] }}</strong>
        @foreach($billTo['contacts'] as $contact)
            <br>{{ $contact }}
        @endforeach
        @if($billTo['address'])
            <br><span class="muted">{{ $billTo['address'] }}</span>
        @endif
    </p>

    <p style="margin-top: 14px;">Dengan hormat,<br>Bersama surat ini kami sampaikan tagihan pembayaran dengan rincian sebagai berikut:</p>

    <table class="rab">
        <thead>
            <tr>
                <th style="width: 22px;">NO</th>
                <th>URAIAN</th>
                <th style="width: 110px;">JUMLAH (Rp)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($letter['summaryRows'] as $row)
            <tr>
                <td class="center">{{ $row['no'] }}</td>
                <td>
                    {{ $row['description'] }}
                    @if($row['detail'])
                        <div class="detail">{{ $row['detail'] }}</div>
                    @endif
                </td>
                <td class="num">{{ $rp($row['amount']) }}</td>
            </tr>
            @endforeach
            @foreach($letter['adjustments'] as $adjustment)
            <tr class="adjustment">
                <td colspan="2" class="num">{{ $adjustment['label'] }}</td>
                <td class="num">{{ $signed($adjustment['amount']) }}</td>
            </tr>
            @endforeach
            <tr class="total">
                <td colspan="2" class="num">TOTAL TAGIHAN</td>
                <td class="num">Rp {{ $rp($letter['total']) }}</td>
            </tr>
        </tbody>
    </table>
    <p class="words">TERBILANG: {{ $letter['totalInWords'] }}</p>

    <p style="margin-bottom: 2px;"><strong>Cara Pembayaran</strong></p>
    @if(count($payment['accounts']) > 0)
    <p style="margin-bottom: 2px;">Transfer ke salah satu rekening berikut{{ $payment['holder'] ? ' a.n. '.$payment['holder'] : '' }}:</p>
    <table class="terms">
        <thead>
            <tr>
                <th style="width: 22px;">NO</th>
                <th>BANK</th>
                <th>NO. REKENING</th>
            </tr>
        </thead>
        <tbody>
            @foreach($payment['accounts'] as $index => $account)
            <tr>
                <td style="text-align: center;">{{ $index + 1 }}</td>
                <td>{{ $account['bank'] }}</td>
                <td>{{ $account['accountNo'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @else
    <p style="margin-bottom: 2px;">Hubungi kami untuk rekening pembayaran.</p>
    @endif
    <p class="instruction">{{ $payment['instruction'] }}</p>

    @if(count($letter['notes']) > 0)
    <p style="margin-bottom: 2px;">Catatan:</p>
    <ol class="notes">
        @foreach($letter['notes'] as $note)
            <li>{{ $note }}</li>
        @endforeach
    </ol>
    @endif

    <p class="closing">{{ $letter['closing'] }}</p>

    @include('pdf.layouts.partials.signature', ['company' => $company, 'signer' => $signer])
</body>
</html>
