<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Http\Requests\HR\DivisionRequest;
use App\Http\Requests\HR\PositionRequest;
use App\Models\Division;
use App\Models\Position;
use App\Services\OrganizationStructureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SDM (Sprint 10, decision #10) — "Divisi & Jabatan": the structured job
 * master every employee and KPI template points to. HR manages it, the
 * CEO reads it (module:hr + role:HR on writes).
 */
class OrganizationStructureController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('HR/Structure/Index', [
            'divisions' => Division::query()
                ->ordered()
                ->withCount('positions')
                ->with(['positions' => fn ($query) => $query->ordered()->withCount(['employees' => fn ($q) => $q->hrEligible()])])
                ->get(),
            'canManage' => $request->user()->hasAnyRole(['HR', 'SUPERADMIN']),
        ]);
    }

    public function storeDivision(DivisionRequest $request, OrganizationStructureService $service): RedirectResponse
    {
        $division = $service->createDivision($request->validated(), $request->user());

        return back()->with('success', "Divisi {$division->name} berhasil ditambahkan.");
    }

    public function updateDivision(DivisionRequest $request, Division $division, OrganizationStructureService $service): RedirectResponse
    {
        $service->updateDivision($division, $request->validated(), $request->user());

        return back()->with('success', "Divisi {$division->name} berhasil diperbarui.");
    }

    public function destroyDivision(Request $request, Division $division, OrganizationStructureService $service): RedirectResponse
    {
        $service->deleteDivision($division, $request->user());

        return back()->with('success', "Divisi {$division->name} berhasil dihapus.");
    }

    public function storePosition(PositionRequest $request, OrganizationStructureService $service): RedirectResponse
    {
        $position = $service->createPosition($request->validated(), $request->user());

        return back()->with('success', "Jabatan {$position->name} berhasil ditambahkan.");
    }

    public function updatePosition(PositionRequest $request, Position $position, OrganizationStructureService $service): RedirectResponse
    {
        $service->updatePosition($position, $request->validated(), $request->user());

        return back()->with('success', "Jabatan {$position->name} berhasil diperbarui.");
    }

    public function destroyPosition(Request $request, Position $position, OrganizationStructureService $service): RedirectResponse
    {
        $service->deletePosition($position, $request->user());

        return back()->with('success', "Jabatan {$position->name} berhasil dihapus.");
    }
}
