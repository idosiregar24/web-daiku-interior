<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdatePublicProfileRequest;
use App\Http\Requests\Settings\UpdateSiteSettingRequest;
use App\Http\Requests\Settings\UploadBrandingAssetRequest;
use App\Models\SiteSetting;
use App\Services\SiteSettingService;
use App\Support\CompanyProfile\Placeholder;
use App\Support\CompanyProfile\ServiceCatalog;
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
        $settings = SiteSetting::current();
        $city = ServiceCatalog::city();

        return Inertia::render('Settings/Edit', [
            'settings' => $settings,
            // Sprint 20 Sub 05 — shown as the "Profil Publik" fields' placeholders.
            'publicDefaults' => [
                'public_tagline' => Placeholder::PUBLIC_TAGLINE,
                'hero_headline' => Placeholder::heroHeadline($city),
                'hero_subheadline' => Placeholder::HERO_SUBHEADLINE,
                'about_text' => Placeholder::about($city),
                'service_area_text' => Placeholder::serviceArea($city),
                'opening_hours' => Placeholder::OPENING_HOURS,
                'whatsapp_greeting' => strtr(Placeholder::WHATSAPP_GREETING, [':name' => $settings->site_name]),
            ],
            'siteUrl' => route('site.home'),
        ]);
    }

    public function update(UpdateSiteSettingRequest $request, SiteSettingService $service): RedirectResponse
    {
        $service->update($request->validated(), $request->user());

        return back()->with('success', 'Pengaturan situs berhasil disimpan.');
    }

    /** Sprint 20 Sub 05 — "Profil Publik": the company profile's text at `/`. */
    public function updatePublicProfile(UpdatePublicProfileRequest $request, SiteSettingService $service): RedirectResponse
    {
        $service->update($request->validated(), $request->user());

        return back()->with('success', 'Profil publik berhasil disimpan.');
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
