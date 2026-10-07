<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\CityRequest;
use App\Models\City;
use Illuminate\Http\RedirectResponse;

/** Sprint 16 Sub 08 — Master Kota, the list a lead's city is picked from. */
class CityController extends Controller
{
    public function store(CityRequest $request): RedirectResponse
    {
        City::create($request->validated());

        return back()->with('success', 'Kota berhasil ditambahkan.');
    }

    public function update(CityRequest $request, City $city): RedirectResponse
    {
        $city->update($request->validated());

        return back()->with('success', 'Kota berhasil diperbarui.');
    }

    public function destroy(City $city): RedirectResponse
    {
        // Leads keep pointing at it (FK restrict) — rename instead.
        if ($city->leads()->exists()) {
            return back()->withErrors(['name' => 'Kota ini masih dipakai lead — ubah namanya saja, jangan dihapus.']);
        }

        $city->delete();

        return back()->with('success', 'Kota berhasil dihapus.');
    }
}
