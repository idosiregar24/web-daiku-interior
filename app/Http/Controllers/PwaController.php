<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use GdImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sprint 13 H7 — what makes the app installable on a phone's home screen:
 * the web app manifest (name from Pengaturan Situs) and its icons, drawn
 * here from the uploaded logo (or a "D" monogram when there's none).
 * Public like branding.show — the login page must already be installable.
 * Nothing here is cached offline (H6); public/sw.js only exists so the
 * browser offers "Tambahkan ke layar utama".
 */
class PwaController extends Controller
{
    public const SIZES = [180, 192, 512];

    public const PURPOSES = ['any', 'maskable'];

    /** Daiku Yellow / Daiku Dark (resources/css/app.css tokens). */
    public const THEME_COLOR = '#f5c518';

    private const YELLOW = [245, 197, 24];

    private const DARK = [26, 26, 26];

    public function manifest(): JsonResponse
    {
        $site = SiteSetting::current();
        $version = $this->version($site);
        $icon = fn (int $size, string $purpose) => [
            'src' => route('pwa.icon', ['size' => $size, 'purpose' => $purpose, 'v' => $version], false),
            'sizes' => "{$size}x{$size}",
            'type' => 'image/png',
            'purpose' => $purpose,
        ];

        return response()->json([
            'name' => $site->site_name,
            'short_name' => Str::limit($site->site_name, 12, ''),
            'description' => $site->site_tagline ?: SiteSetting::DEFAULT_TAGLINE,
            'lang' => 'id',
            // Sprint 20 K3 — `/` is the company profile now; `/app` is the
            // login or (RoleRedirectService) the role's own first page.
            'start_url' => route('app.home', [], false),
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => self::THEME_COLOR,
            'icons' => [$icon(192, 'any'), $icon(512, 'any'), $icon(512, 'maskable')],
        ], 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=3600',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function icon(int $size, string $purpose): Response
    {
        abort_unless(in_array($size, self::SIZES, true) && in_array($purpose, self::PURPOSES, true), 404);

        // Drawn once per logo version and size: the route is public, and a
        // 4x GD canvas per request would let anyone keep the CPU busy.
        $site = SiteSetting::current();
        $png = base64_decode(Cache::remember(
            "pwa-icon:{$this->version($site)}:{$size}:{$purpose}",
            now()->addWeek(),
            fn () => base64_encode($this->draw($size, $purpose === 'maskable', $this->logo($site))),
        ));

        return response($png, 200, [
            'Content-Type' => 'image/png',
            // The URL carries the logo version (`v`), so it can be cached for good.
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Changes whenever the logo does — busts cached icons after an upload. */
    private function version(SiteSetting $site): string
    {
        return substr(md5((string) $site->logo_path), 0, 10);
    }

    /** The uploaded logo as a GD image, or null (none, missing, or a format GD can't read such as SVG). */
    private function logo(SiteSetting $site): ?GdImage
    {
        $disk = Storage::disk(SiteSetting::DISK);

        if (! $site->logo_path || ! $disk->exists($site->logo_path)) {
            return null;
        }

        $image = @imagecreatefromstring((string) $disk->get($site->logo_path));

        return $image instanceof GdImage ? $image : null;
    }

    /**
     * A square icon: the logo centred on white, or the "D" monogram on
     * Daiku Yellow. A maskable icon keeps its content inside the central
     * safe zone (launchers crop up to a circle of 80%). Drawn at 4x and
     * scaled down, because GD doesn't antialias filled shapes.
     */
    private function draw(int $size, bool $maskable, ?GdImage $logo): string
    {
        $big = $size * 4;
        $canvas = imagecreatetruecolor($big, $big);
        imagealphablending($canvas, true);

        if ($logo) {
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            $box = (int) round($big * ($maskable ? 0.6 : 0.82));
            $ratio = min($box / imagesx($logo), $box / imagesy($logo));
            $width = (int) round(imagesx($logo) * $ratio);
            $height = (int) round(imagesy($logo) * $ratio);
            imagecopyresampled($canvas, $logo, intdiv($big - $width, 2), intdiv($big - $height, 2), 0, 0, $width, $height, imagesx($logo), imagesy($logo));
        } else {
            $yellow = imagecolorallocate($canvas, ...self::YELLOW);
            $dark = imagecolorallocate($canvas, ...self::DARK);
            imagefill($canvas, 0, 0, $yellow);

            // A thick "D": rectangle + half disc in dark, the same shape
            // inset by the stroke width cut back out in yellow.
            $letter = (int) round($big * ($maskable ? 0.42 : 0.54));
            $stroke = (int) round($letter * 0.24);
            $x0 = intdiv($big - $letter, 2) + intdiv($letter, 12);
            $y0 = intdiv($big - $letter, 2);
            $this->dShape($canvas, $x0, $y0, $letter, $letter, $dark);
            $this->dShape($canvas, $x0 + $stroke, $y0 + $stroke, $letter - 2 * $stroke, $letter - 2 * $stroke, $yellow);
        }

        $out = imagecreatetruecolor($size, $size);
        imagecopyresampled($out, $canvas, 0, 0, 0, 0, $size, $size, $big, $big);

        ob_start();
        imagepng($out);

        return (string) ob_get_clean();
    }

    /** Left half a rectangle, right half a half-ellipse: the letter D. */
    private function dShape(GdImage $canvas, int $x, int $y, int $width, int $height, int $color): void
    {
        $half = intdiv($width, 2);
        imagefilledrectangle($canvas, $x, $y, $x + $half, $y + $height, $color);
        imagefilledellipse($canvas, $x + $half, $y + intdiv($height, 2), $width, $height, $color);
    }
}
