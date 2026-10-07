{{--
    Invoice PDF — Sprint 17 Sub 05: the billing layout (layouts/invoice, data
    from App\Support\Letters\InvoiceLetter). Inputs: $invoice, $siteSettings.
--}}
@php
    $invoice->loadMissing(['lead.city', 'project', 'quotation.items', 'quotation.sections', 'termin.paymentTerm']);
    $letter = \App\Support\Letters\InvoiceLetter::for($invoice, $siteSettings);
@endphp
@include('pdf.layouts.invoice', ['letter' => $letter])
