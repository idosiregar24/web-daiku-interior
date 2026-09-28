<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StoreLeadCategoryRequest;
use App\Http\Requests\MasterData\UpdateLeadCategoryRequest;
use App\Models\LeadCategory;
use Illuminate\Http\RedirectResponse;

class LeadCategoryController extends Controller
{
    public function store(StoreLeadCategoryRequest $request): RedirectResponse
    {
        LeadCategory::create($request->validated());

        return back()->with('success', 'Kategori berhasil ditambahkan.');
    }

    public function update(UpdateLeadCategoryRequest $request, LeadCategory $leadCategory): RedirectResponse
    {
        $leadCategory->update($request->validated());

        return back()->with('success', 'Kategori berhasil diperbarui.');
    }

    public function destroy(LeadCategory $leadCategory): RedirectResponse
    {
        // Deleting would null the lead's FK and force re-picking a kategori
        // on its next edit — rename it instead.
        if ($leadCategory->leads()->exists()) {
            return back()->withErrors(['name' => 'Kategori ini masih dipakai lead — ubah namanya saja, jangan dihapus.']);
        }

        $leadCategory->delete();

        return back()->with('success', 'Kategori berhasil dihapus.');
    }
}
