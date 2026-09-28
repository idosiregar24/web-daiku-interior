<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the uploaded brand assets (logo, favicon, login image) from the
 * private disk. Public on purpose — the login page and the browser tab
 * need them before anyone is signed in. URLs carry a `v` fingerprint
 * (SiteSetting::assetUrl), so responses are cacheable forever.
 */
class BrandingAssetController extends Controller
{
    public function show(string $asset): StreamedResponse
    {
        $path = SiteSetting::current()->{SiteSetting::ASSETS[$asset]};
        $disk = Storage::disk(SiteSetting::DISK);

        abort_unless($path && $disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
