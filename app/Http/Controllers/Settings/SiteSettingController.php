<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSiteSettingRequest;
use App\Http\Requests\Settings\UploadBrandingAssetRequest;
use App\Models\SiteSetting;
use App\Services\SiteSettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Site Settings / web customization — CEO + SUPERADMIN only (not itemized
 * in PRD §7.1 — added on request). The settings row is a singleton
 * (edit/update only); the brand assets in SiteSetting::ASSETS each get
 * upload-or-replace (storeAsset) and remove (destroyAsset).
 */
class SiteSettingController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Settings/Edit', [
            'settings' => SiteSetting::current(),
        ]);
    }

    public function update(UpdateSiteSettingRequest $request, SiteSettingService $service): RedirectResponse
    {
        $service->update($request->validated(), $request->user());

        return back()->with('success', 'Pengaturan situs berhasil disimpan.');
    }

    public function storeAsset(UploadBrandingAssetRequest $request, string $asset, SiteSettingService $service): RedirectResponse
    {
        $service->storeAsset($asset, $request->file('file'), $request->user());

        return back()->with('success', 'Gambar berhasil diunggah.');
    }

    public function destroyAsset(Request $request, string $asset, SiteSettingService $service): RedirectResponse
    {
        $service->deleteAsset($asset, $request->user());

        return back()->with('success', 'Gambar berhasil dihapus.');
    }
}
