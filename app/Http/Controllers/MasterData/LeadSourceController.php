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
        $leadSource->delete();

        return back()->with('success', 'Sumber lead berhasil dihapus.');
    }
}
