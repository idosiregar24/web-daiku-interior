<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Http\Requests\HR\DailyActivityRequest;
use App\Models\DailyActivity;
use App\Services\DailyActivityService;
use App\Support\DailyFormSchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 29 Sub 13 (I1) — "Aktivitas Harian": the signed-in account's own
 * entries. Today on top (add / change / remove — today only), the last
 * 14 days below with HR's check. Every account except a Tukang (route
 * `role:` + DailyActivityPolicy).
 */
class DailyActivityController extends Controller
{
    public function index(Request $request, DailyActivityService $service): Response
    {
        $this->authorize('create', DailyActivity::class);

        $user = $request->user();
        $days = $service->ownDays($user);

        return Inertia::render('HR/Activities/Index', [
            'today' => DailyActivityService::today()->toDateString(),
            'isWorkDay' => DailyFormSchedule::isWorkDay(DailyActivityService::today()),
            'activities' => $days['today'],
            'history' => $days['history'],
            'projects' => $service->projectOptions($user),
        ]);
    }

    public function store(DailyActivityRequest $request, DailyActivityService $service): RedirectResponse
    {
        $this->authorize('create', DailyActivity::class);

        $service->create($request->user(), $request->validated());

        return back()->with('success', 'Aktivitas disimpan.');
    }

    public function update(DailyActivityRequest $request, DailyActivity $dailyActivity, DailyActivityService $service): RedirectResponse
    {
        $this->authorize('update', $dailyActivity);

        $service->update($dailyActivity, $request->validated(), $request->user());

        return back()->with('success', 'Aktivitas diperbarui.');
    }

    public function destroy(DailyActivity $dailyActivity, DailyActivityService $service): RedirectResponse
    {
        $this->authorize('delete', $dailyActivity);

        $service->delete($dailyActivity);

        return back()->with('success', 'Aktivitas dihapus.');
    }
}
