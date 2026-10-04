<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StoreUnitRequest;
use App\Http\Requests\MasterData\UpdateUnitRequest;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;

/**
 * Sprint 11 Sub 1 — Data Master → Satuan (SUPERADMIN). Listed by
 * MasterDataController::index().
 */
class UnitController extends Controller
{
    public function store(StoreUnitRequest $request): RedirectResponse
    {
        Unit::create([
            ...$request->validated(),
            'sort_order' => $request->validated('sort_order') ?? 0,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('success', 'Satuan berhasil ditambahkan.');
    }

    public function update(UpdateUnitRequest $request, Unit $unit): RedirectResponse
    {
        $unit->update([
            ...$request->validated(),
            'sort_order' => $request->validated('sort_order') ?? 0,
            'is_active' => $request->boolean('is_active', $unit->is_active),
        ]);

        return back()->with('success', 'Satuan berhasil diperbarui.');
    }

    public function destroy(Unit $unit): RedirectResponse
    {
        // Materials and RAB lines point at it (restrictOnDelete) —
        // deactivating hides it from the dropdowns and keeps that history.
        if ($unit->isInUse()) {
            return back()->withErrors(['code' => "Satuan {$unit->code} sudah dipakai — nonaktifkan saja, jangan dihapus."]);
        }

        $unit->delete();

        return back()->with('success', 'Satuan berhasil dihapus.');
    }
}
