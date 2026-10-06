{{--
    Offer (RAB) PDF — Sprint 15: rendered as the company letter
    (layouts/letter, data from App\Support\Letters\QuotationLetter).
    Inputs: $quotation, $siteSettings, $validityDays.
--}}
@php
    $quotation->loadMissing(['lead', 'items.unit', 'sections', 'paymentTerms']);
    $letter = \App\Support\Letters\QuotationLetter::for($quotation, $siteSettings, $validityDays);
@endphp
@include('pdf.layouts.letter', ['letter' => $letter])
