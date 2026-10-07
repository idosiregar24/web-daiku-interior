{{--
    Sprint 17 Sub 05 (K3) — the invoice as a bill, distinct from the offer
    letter: big INVOICE title, "Ditagihkan kepada" + invoice data, a short
    summary table, the Total Tagihan box, "Cara Pembayaran" and a status
    stamp. Same letterhead / footer / signature as the offer (frame partials).
    Fed by App\Support\Letters\InvoiceLetter ($letter). PDF only.
    DomPDF renders a subset of CSS 2.1: tables and fixed boxes only, no flexbox.
--}}
@php
    $rp = fn ($amount) => number_format((float) $amount, 0, ',', '.');
    $signed = fn ($amount) => $amount < 0 ? '- '.$rp(-$amount) : $rp($amount);
    $company = $letter['company'];
    $signer = $letter['signer'];
    $billTo = $letter['billTo'];
    $payment = $letter['paymentInfo'];
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

        table.head { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.head td { vertical-align: bottom; padding: 0; }
        .title { font-size: 28px; font-weight: bold; letter-spacing: 3px; line-height: 1; }
        .accent { width: 64px; height: 4px; background: #f5c518; margin-top: 6px; }
        .subject { font-size: 9.5px; color: #6b6b6b; margin-top: 6px; }
        .stamp { display: inline-block; border: 2px solid #8a8a8a; color: #6b6b6b; font-size: 12px; font-weight: bold; padding: 4px 12px; letter-spacing: 2px; }
        .stamp-warning { border-color: #b54708; color: #b54708; }
        .stamp-success { border-color: #067647; color: #067647; }

        table.parties { width: 100%; border-collapse: separate; border-spacing: 0; margin-bottom: 16px; }
        table.parties td.box { width: 48%; vertical-align: top; border: 1px solid #e4e4e7; background: #fafaf9; padding: 10px 12px; }
        table.parties td.gap { width: 4%; }
        .box-label { text-transform: uppercase; font-size: 8.5px; font-weight: bold; letter-spacing: 1px; color: #6b6b6b; margin-bottom: 4px; }
        .bill-name { font-size: 12px; font-weight: bold; margin-bottom: 2px; }
        table.meta { width: 100%; border-collapse: collapse; }
        table.meta td { padding: 1px 0; vertical-align: top; }
        table.meta td.label { width: 92px; color: #6b6b6b; white-space: nowrap; }
        table.meta td.value { font-weight: bold; }
        .draft { color: #b42318; font-weight: bold; }

        table.summary { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.summary th { background: #fff6d9; color: #6b6b6b; font-size: 8.5px; letter-spacing: 0.6px; text-align: left; padding: 6px 8px; border-bottom: 1px solid #e4e4e7; }
        table.summary td { padding: 7px 8px; border-bottom: 1px solid #e4e4e7; vertical-align: top; }
        table.summary .no { width: 22px; text-align: center; }
        table.summary .amount { width: 120px; text-align: right; white-space: nowrap; }
        table.summary .detail { font-size: 9px; color: #6b6b6b; margin-top: 2px; }

        table.totals { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        table.totals td { vertical-align: top; padding: 0; }
        table.adjustments { width: 100%; border-collapse: collapse; }
        table.adjustments td { padding: 2px 8px; }
        table.adjustments td.amount { text-align: right; white-space: nowrap; width: 120px; }
        .total-box { background: #f5c518; padding: 10px 12px; margin-top: 4px; }
        .total-box table { width: 100%; border-collapse: collapse; }
        /* DomPDF ignores vertical-align here: both cells top-aligned, the label nudged onto the amount's baseline. */
        .total-box td { padding: 0; vertical-align: top; }
        .total-box .label { text-transform: uppercase; font-size: 10px; font-weight: bold; letter-spacing: 1px; padding-top: 6px; }
        .total-box .value { font-size: 17px; font-weight: bold; text-align: right; white-space: nowrap; }
        .words { font-size: 9px; font-style: italic; padding-right: 18px; }

        .pay { border: 1px solid #e4e4e7; border-left: 4px solid #1a1a1a; padding: 10px 12px; margin-bottom: 14px; }
        table.accounts { border-collapse: collapse; margin: 2px 0 6px; }
        table.accounts td { padding: 1px 14px 1px 0; }
        table.accounts td.bank { font-weight: bold; }
        .instruction { font-weight: bold; }

        ol.notes { margin: 2px 0 10px 0; padding-left: 18px; font-size: 9.5px; }
        ol.notes li { margin-bottom: 2px; }
        .closing { font-size: 9.5px; margin-top: 6px; }
    </style>
</head>
<body>
    @include('pdf.layouts.partials.letterhead', ['company' => $company])

    <table class="head">
        <tr>
            <td>
                <div class="title">INVOICE</div>
                <div class="accent"></div>
                <div class="subject">{{ $letter['subject'] }}</div>
            </td>
            <td style="text-align: right;">
                <span class="stamp stamp-{{ $letter['status']['tone'] }}">{{ $letter['status']['label'] }}</span>
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td class="box">
                <div class="box-label">Ditagihkan kepada</div>
                <div class="bill-name">{{ $billTo['name'] }}</div>
                @foreach($billTo['contacts'] as $contact)
                    <div>{{ $contact }}</div>
                @endforeach
                @if($billTo['address'])
                    <div class="muted">{{ $billTo['address'] }}</div>
                @endif
            </td>
            <td class="gap"></td>
            <td class="box">
                <div class="box-label">Data Invoice</div>
                <table class="meta">
                    @if($letter['draft'])
                    <tr>
                        <td class="label">No. Invoice</td>
                        <td class="value"><span class="draft">DRAF</span> <span class="muted" style="font-weight: normal;">— nomor terbit saat invoice diterbitkan</span></td>
                    </tr>
                    @endif
                    @foreach($letter['meta'] as $meta)
                    <tr>
                        <td class="label">{{ $meta['label'] }}</td>
                        <td class="value">{{ $meta['value'] }}</td>
                    </tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>

    <table class="summary">
        <thead>
            <tr>
                <th class="no">NO</th>
                <th>URAIAN</th>
                <th class="amount">JUMLAH (Rp)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($letter['summaryRows'] as $row)
            <tr>
                <td class="no">{{ $row['no'] }}</td>
                <td>
                    {{ $row['description'] }}
                    @if($row['detail'])
                        <div class="detail">{{ $row['detail'] }}</div>
                    @endif
                </td>
                <td class="amount">{{ $rp($row['amount']) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="words" style="width: 52%;">
                <span class="muted" style="font-style: normal;">Terbilang:</span><br>
                {{ $letter['totalInWords'] }}
            </td>
            <td>
                @if(count($letter['adjustments']) > 0)
                <table class="adjustments">
                    @foreach($letter['adjustments'] as $adjustment)
                    <tr>
                        <td>{{ $adjustment['label'] }}</td>
                        <td class="amount">{{ $signed($adjustment['amount']) }}</td>
                    </tr>
                    @endforeach
                </table>
                @endif
                <div class="total-box">
                    <table>
                        <tr>
                            <td class="label">Total Tagihan</td>
                            <td class="value">Rp {{ $rp($letter['total']) }}</td>
                        </tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <div class="pay">
        <div class="box-label">Cara Pembayaran</div>
        @if(count($payment['accounts']) > 0)
            <p style="margin-bottom: 2px;">Transfer ke salah satu rekening berikut{{ $payment['holder'] ? ' a.n. '.$payment['holder'] : '' }}:</p>
            <table class="accounts">
                @foreach($payment['accounts'] as $account)
                <tr>
                    <td class="bank">{{ $account['bank'] }}</td>
                    <td>{{ $account['accountNo'] }}</td>
                </tr>
                @endforeach
            </table>
        @else
            <p style="margin-bottom: 2px;">Hubungi kami untuk rekening pembayaran.</p>
        @endif
        <div class="instruction">{{ $payment['instruction'] }}</div>
    </div>

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
