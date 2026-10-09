<?php

namespace App\Http\Controllers\CompanyProfile;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompanyProfile\ReorderPortfolioPhotosRequest;
use App\Http\Requests\CompanyProfile\UpdatePortfolioPhotoRequest;
use App\Http\Requests\CompanyProfile\UploadPortfolioPhotosRequest;
use App\Models\PortfolioItem;
use App\Models\PortfolioPhoto;
use App\Services\PortfolioPhotoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sprint 20 Sub 04 — the photos of one portfolio item. Routes are
 * scoped (`{portfolio}/photos/{photo}`), so a photo id from another item
 * is a 404.
 */
class PortfolioPhotoController extends Controller
{
    public function store(UploadPortfolioPhotosRequest $request, PortfolioItem $portfolio, PortfolioPhotoService $service): RedirectResponse
    {
        $files = $request->file('photos');
        $service->storeMany($portfolio, $files, $request->user());

        return back()->with('success', count($files).' foto diunggah.');
    }

    public function update(UpdatePortfolioPhotoRequest $request, PortfolioItem $portfolio, PortfolioPhoto $photo, PortfolioPhotoService $service): RedirectResponse
    {
        $service->update($photo, $request->validated());

        return back()->with('success', 'Keterangan foto disimpan.');
    }

    public function cover(Request $request, PortfolioItem $portfolio, PortfolioPhoto $photo, PortfolioPhotoService $service): RedirectResponse
    {
        $service->setCover($photo, $request->user());

        return back()->with('success', 'Foto sampul diganti.');
    }

    public function reorder(ReorderPortfolioPhotosRequest $request, PortfolioItem $portfolio, PortfolioPhotoService $service): RedirectResponse
    {
        $service->reorder($portfolio, $request->validated('ids'));

        return back();
    }

    public function destroy(Request $request, PortfolioItem $portfolio, PortfolioPhoto $photo, PortfolioPhotoService $service): RedirectResponse
    {
        $service->delete($photo, $request->user());

        return back()->with('success', 'Foto dihapus.');
    }
}
