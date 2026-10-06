<?php

namespace App\Http\Controllers\Quotation;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Models\QuotationReference;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sprint 14 Sub 01 — serves a RAB request's reference photo from the
 * private disk. Same gate as the quotation page itself (route `role:`);
 * the reference must belong to that quotation (scoped binding). Never
 * reachable from the client's public link.
 */
class QuotationReferenceController extends Controller
{
    public function show(Quotation $quotation, QuotationReference $reference): StreamedResponse
    {
        $disk = Storage::disk(QuotationReference::DISK);

        abort_unless($reference->kind === QuotationReference::KIND_PHOTO && $reference->path && $disk->exists($reference->path), 404);

        return $disk->response($reference->path, $reference->original_name, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
