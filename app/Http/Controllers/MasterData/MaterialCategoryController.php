<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StoreMaterialCategoryRequest;
use App\Models\MaterialCategory;
use Illuminate\Http\RedirectResponse;

/**
 * Sprint 11 decision #11 — Data Master → Kategori Material (SUPERADMIN).
 * Listed by MasterDataController::index().
 */
class MaterialCategoryController extends Controller
{
    public function store(StoreMaterialCategoryRequest $request): RedirectResponse
    {
        MaterialCategory::create([
            ...$request->validated(),
            'sort_order' => $request->validated('sort_order') ?? 0,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('success', 'Kategori material berhasil ditambahkan.');
    }

    /**
     * The prefix only shapes codes of items created from now on — existing
     * codes are identities and never change.
     */
    public function update(StoreMaterialCategoryRequest $request, MaterialCategory $materialCategory): RedirectResponse
    {
        $materialCategory->update([
            ...$request->validated(),
            'sort_order' => $request->validated('sort_order') ?? 0,
            'is_active' => $request->boolean('is_active', $materialCategory->is_active),
        ]);

        return back()->with('success', 'Kategori material berhasil diperbarui.');
    }

    public function destroy(MaterialCategory $materialCategory): RedirectResponse
    {
        if ($materialCategory->materials()->exists()) {
            return back()->withErrors(['name' => "Kategori {$materialCategory->name} sudah dipakai barang — nonaktifkan saja, jangan dihapus."]);
        }

        $materialCategory->delete();

        return back()->with('success', 'Kategori material berhasil dihapus.');
    }
}
