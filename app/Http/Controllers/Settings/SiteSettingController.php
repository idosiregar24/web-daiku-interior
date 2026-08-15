<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSiteSettingRequest;
use App\Models\SiteSetting;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Site Settings — general company/application profile, CEO + SUPERADMIN
 * only (not itemized in PRD §7.1 — added on request). Singleton resource:
 * only `edit`/`update`, no index/create/destroy.
 */
class SiteSettingController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Settings/Edit', [
            'settings' => SiteSetting::current(),
        ]);
    }

    public function update(UpdateSiteSettingRequest $request): RedirectResponse
    {
        SiteSetting::current()->update($request->validated());

        return back()->with('success', 'Pengaturan situs berhasil disimpan.');
    }
}
