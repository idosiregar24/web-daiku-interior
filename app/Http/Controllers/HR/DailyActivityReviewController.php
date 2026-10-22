<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Http\Requests\HR\BulkReviewDailyActivityRequest;
use App\Http\Requests\HR\ReviewDailyActivityRequest;
use App\Services\DailyActivityService;
use App\Support\DailyFormSchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 29 Sub 13 (I2) — "Periksa Aktivitas": one day, every SDM employee
 * with an account, their entries or "Tidak mengisi". HR decides (`role:HR`);
 * the CEO and PMs read (decision I1: "dibaca CEO, PM, HR"). Outside the
 * `module:hr` group on purpose — PMs never open the rest of SDM.
 */
class DailyActivityReviewController extends Controller
{
    public function index(Request $request, DailyActivityService $service): Response
    {
        $default = DailyActivityService::defaultReviewDate();
        $latest = DailyActivityService::today()->subDay();
        $date = $this->requestedDate($request, $latest) ?? $default;
        $sheet = $service->sheet($date->toDateString());

        return Inertia::render('HR/Activities/Review', [
            'date' => $date->toDateString(),
            'defaultDate' => $default->toDateString(),
            'latestDate' => $latest->toDateString(),
            'isWorkDay' => DailyFormSchedule::isWorkDay($date),
            'rows' => $sheet['rows'],
            'summary' => $sheet['summary'],
            'canReview' => $request->user()->hasAnyRole(['HR', 'SUPERADMIN']),
        ]);
    }

    public function store(ReviewDailyActivityRequest $request, DailyActivityService $service): RedirectResponse
    {
        $review = $service->review($request->validated(), $request->user());

        return back()->with('success', "Aktivitas ditandai \"{$review->status->label()}\".");
    }

    public function bulk(BulkReviewDailyActivityRequest $request, DailyActivityService $service): RedirectResponse
    {
        $counts = $service->reviewAllFilled($request->validated('date'), $request->user());

        if ($counts['sesuai'] + $counts['tidak_mengisi'] === 0) {
            return back()->with('success', 'Semua karyawan sudah diperiksa.');
        }

        $parts = array_filter([
            $counts['sesuai'] > 0 ? "{$counts['sesuai']} orang ditandai Sesuai" : null,
            $counts['tidak_mengisi'] > 0 ? "{$counts['tidak_mengisi']} orang dicatat Tidak mengisi" : null,
        ]);

        return back()->with('success', implode(', ', $parts).'.');
    }

    /** `?date=Y-m-d` up to yesterday; anything else falls back to the default day. */
    private function requestedDate(Request $request, Carbon $latest): ?Carbon
    {
        $value = (string) $request->query('date');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = rescue(fn () => Carbon::createFromFormat('Y-m-d', $value, DailyFormSchedule::TIMEZONE)?->startOfDay(), null, false);

        // An overflowing date (2026-02-31) parses into another day — refuse it.
        return $date && $date->toDateString() === $value && $date->lte($latest) ? $date : null;
    }
}
