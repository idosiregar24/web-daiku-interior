{{--
    Termin PDF (Keuangan → Termin, Detail Proyek) for a termin Marketing
    hasn't invoiced yet — Sprint 17 Sub 05: the same billing layout as an
    invoice (layouts/invoice, App\Support\Letters\InvoiceLetter::forTermin()),
    marked DRAF until the invoice and its number exist. An invoiced termin
    streams its invoice instead (TerminController::exportPdf()).
    Inputs: $termin, $siteSettings.
--}}
@php
    $termin->loadMissing(['project.lead.city', 'project.quotation', 'quotation', 'paymentTerm']);
    $letter = \App\Support\Letters\InvoiceLetter::forTermin($termin, $siteSettings);
@endphp
@include('pdf.layouts.invoice', ['letter' => $letter])
