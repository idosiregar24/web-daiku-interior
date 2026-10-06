{{--
    Invoice PDF — Sprint 15: rendered as the company letter (layouts/letter,
    data from App\Support\Letters\InvoiceLetter). Inputs: $invoice, $siteSettings.
--}}
@php
    $invoice->loadMissing(['lead', 'quotation.items.unit', 'quotation.sections', 'termin']);
    $letter = \App\Support\Letters\InvoiceLetter::for($invoice, $siteSettings);
@endphp
@include('pdf.layouts.letter', ['letter' => $letter])
