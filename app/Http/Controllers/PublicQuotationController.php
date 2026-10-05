<?php

namespace App\Http\Controllers;

use App\Http\Requests\Quotation\ApprovePublicQuotationRequest;
use App\Http\Resources\PublicQuotationResource;
use App\Models\QuotationShareLink;
use App\Services\QuotationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sprint 12 decisions #13–#14 — the client's page, opened from the link
 * Marketing sends (no login). The token is the only credential; the
 * route is throttled and never indexed. Data goes through
 * PublicQuotationResource's whitelist; the rules for when approving is
 * possible live in QuotationService::publicState()/clientApprove().
 */
class PublicQuotationController extends Controller
{
    public function show(Request $request, string $token, QuotationService $service): Response
    {
        $link = $this->link($token);

        return Inertia::render('Public/Quotation', [
            'token' => $token,
            'state' => $service->publicState($link),
            'quotation' => (new PublicQuotationResource($link->quotation))->resolve($request),
        ])->toResponse($request)
            ->header('X-Robots-Tag', 'noindex, nofollow')
            // The token is in the URL — never hand it to another site in a Referer.
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function approve(ApprovePublicQuotationRequest $request, string $token, QuotationService $service): RedirectResponse
    {
        $service->clientApprove($this->link($token), $request->boolean('agree'), $request->ip(), $request->userAgent());

        return back()->with('success', 'Terima kasih — penawaran telah Anda setujui. Marketing kami akan segera menghubungi Anda.');
    }

    private function link(string $token): QuotationShareLink
    {
        return QuotationShareLink::query()
            ->where('token', $token)
            ->with(['quotation.lead:id,client_name,address', 'quotation.items.unit', 'quotation.sections', 'quotation.paymentTerms', 'sender'])
            ->firstOrFail();
    }
}
