<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\RecordTerminPaymentRequest;
use App\Models\BankAccount;
use App\Models\SiteSetting;
use App\Models\Termin;
use App\Services\TerminService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRD §7.1 "Finance – Termin" row: CEO/Finance read, PM create-only
 * (scoped via ProjectController::show()'s `termins` prop + `store()`
 * here — see .claude/plan/README.md for why PM has no dedicated list
 * route), Finance read+update (mark paid).
 */
class TerminController extends Controller
{
    /**
     * Sends both the flat paginated list (existing behavior) and a
     * calendar-month slice (PRD §8.4 "Finance: ... termin calendar view")
     * — `Pages/Finance/Termins/Index.tsx` toggles between them client-side
     * without a full reload; only month navigation inside the calendar
     * re-requests (Inertia partial reload, `only: ['calendarTermins',
     * 'calendarMonth']`).
     */
    public function index(Request $request): Response
    {
        $termins = Termin::query()
            ->with(['project:id,name', 'milestone:id,name', 'bankAccount:id,label'])
            ->byStatus($request->string('status')->value() ?: null)
            ->orderBy('scheduled_date')
            ->paginate(20)
            ->withQueryString();

        // `!` = day 1: plain 'Y-m' takes today's day, so on the 29th–31st
        // February (or any shorter month) overflowed into the next one.
        // A malformed value falls back to the current month instead of a 500.
        $requestedMonth = $request->string('month')->value();
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requestedMonth)
            ? Carbon::createFromFormat('!Y-m', $requestedMonth)
            : now()->startOfMonth();

        // Grid always shows full weeks (Senin–Minggu), so it pads a few
        // days from the adjacent months in/out of view.
        $gridStart = $month->copy()->startOfWeek(Carbon::MONDAY);
        $gridEnd = $month->copy()->endOfMonth()->endOfWeek(Carbon::MONDAY);

        $calendarTermins = Termin::query()
            ->with(['project:id,name', 'milestone:id,name'])
            ->whereBetween('scheduled_date', [$gridStart->toDateString(), $gridEnd->toDateString()])
            ->orderBy('scheduled_date')
            ->get();

        return Inertia::render('Finance/Termins/Index', [
            'termins' => $termins,
            'filters' => $request->only(['status']),
            'calendarTermins' => $calendarTermins,
            'calendarMonth' => $month->format('Y-m'),
            // "Catat Pembayaran" dialog (FINANCE only — the route enforces it).
            'bankAccounts' => $request->user()->hasAnyRole(['FINANCE', 'SUPERADMIN'])
                ? BankAccount::where('is_active', true)->orderBy('label')->get(['id', 'label'])
                : [],
        ]);
    }

    public function markPaid(Termin $termin, TerminService $service): RedirectResponse
    {
        $service->markPaid($termin, request()->user());

        return back()->with('success', 'Termin ditandai sudah dibayar.');
    }

    /** PRD §4.7 "DP + pelunasan" — one partial payment; see TerminService::recordPayment(). */
    public function recordPayment(RecordTerminPaymentRequest $request, Termin $termin, TerminService $service): RedirectResponse
    {
        $service->recordPayment($termin, $request->validated(), $request->user());

        return back()->with('success', 'Pembayaran termin berhasil dicatat.');
    }

    public function exportPdf(Termin $termin): HttpResponse
    {
        $termin->load(['project:id,name', 'project.lead:id,client_name', 'milestone:id,name']);

        $pdf = Pdf::loadView('pdf.termin', [
            'termin' => $termin,
            'siteSettings' => SiteSetting::current(),
        ]);

        return $pdf->stream("invoice-termin-{$termin->termin_number}-{$termin->project->name}.pdf");
    }
}
