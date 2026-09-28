<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StoreLeadSourceRequest;
use App\Http\Requests\MasterData\UpdateLeadSourceRequest;
use App\Models\LeadSource;
use Illuminate\Http\RedirectResponse;

class LeadSourceController extends Controller
{
    public function store(StoreLeadSourceRequest $request): RedirectResponse
    {
        LeadSource::create($request->validated());

        return back()->with('success', 'Sumber lead berhasil ditambahkan.');
    }

    public function update(UpdateLeadSourceRequest $request, LeadSource $leadSource): RedirectResponse
    {
        $leadSource->update($request->validated());

        return back()->with('success', 'Sumber lead berhasil diperbarui.');
    }

    public function destroy(LeadSource $leadSource): RedirectResponse
    {
        // Deleting would null the lead's FK and force re-picking a sumber
        // on its next edit — rename it instead.
        if ($leadSource->leads()->exists()) {
            return back()->withErrors(['name' => 'Sumber lead ini masih dipakai lead — ubah namanya saja, jangan dihapus.']);
        }

        $leadSource->delete();

        return back()->with('success', 'Sumber lead berhasil dihapus.');
    }
}
