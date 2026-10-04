<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StoreMaterialSynonymRequest;
use App\Models\MaterialSynonym;
use App\Services\MaterialCatalogService;
use Illuminate\Http\RedirectResponse;

/**
 * Sprint 11 §5.5 Lapis 2 — Data Master → Sinonim Barang (SUPERADMIN).
 * Every change rebuilds the catalog's match keys: items that now turn out
 * to be the same get flagged for the Cek Duplikat page (never deleted).
 */
class MaterialSynonymController extends Controller
{
    public function store(StoreMaterialSynonymRequest $request, MaterialCatalogService $catalog): RedirectResponse
    {
        MaterialSynonym::create($request->validated());

        return back()->with('success', $this->rebuilt($catalog, 'Sinonim berhasil ditambahkan.'));
    }

    public function update(StoreMaterialSynonymRequest $request, MaterialSynonym $materialSynonym, MaterialCatalogService $catalog): RedirectResponse
    {
        $materialSynonym->update($request->validated());

        return back()->with('success', $this->rebuilt($catalog, 'Sinonim berhasil diperbarui.'));
    }

    public function destroy(MaterialSynonym $materialSynonym, MaterialCatalogService $catalog): RedirectResponse
    {
        $materialSynonym->delete();

        return back()->with('success', $this->rebuilt($catalog, 'Sinonim berhasil dihapus.'));
    }

    private function rebuilt(MaterialCatalogService $catalog, string $message): string
    {
        $flagged = $catalog->rebuildMatchKeys();

        return $flagged > 0 ? "{$message} {$flagged} barang kini ditandai kemungkinan dobel — cek di Logistik → Cek Duplikat." : $message;
    }
}
