<?php

use App\Models\SiteSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Sprint 13 H7 — the app is installable: a public manifest named after
 * Pengaturan Situs, PNG icons in the declared sizes, and a service worker.
 */

test('the manifest is public and names the app after Pengaturan Situs', function () {
    SiteSetting::current()->update(['site_name' => 'Daiku Interior', 'site_tagline' => 'Sistem Internal']);

    $response = $this->get(route('pwa.manifest'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json');

    expect($response->json())
        ->name->toBe('Daiku Interior')
        ->short_name->toBe('Daiku Interi')
        ->start_url->toBe('/')
        ->display->toBe('standalone')
        ->and(collect($response->json('icons'))->pluck('sizes')->all())->toBe(['192x192', '512x512', '512x512'])
        ->and(collect($response->json('icons'))->pluck('purpose')->all())->toBe(['any', 'any', 'maskable']);
});

test('every declared icon is a PNG of its size', function () {
    foreach ($this->get(route('pwa.manifest'))->json('icons') as $icon) {
        $png = $this->get($icon['src'])->assertOk()->assertHeader('Content-Type', 'image/png')->getContent();
        [$width, $height] = getimagesizefromstring($png);

        expect("{$width}x{$height}")->toBe($icon['sizes']);
    }
});

test('the icons are drawn from the uploaded logo, and their URL changes with it', function () {
    Storage::fake(SiteSetting::DISK);
    $before = $this->get(route('pwa.manifest'))->json('icons.0.src');

    $path = UploadedFile::fake()->image('logo.png', 300, 120)->store('branding', SiteSetting::DISK);
    SiteSetting::current()->update(['logo_path' => $path]);

    $after = $this->get(route('pwa.manifest'))->json('icons.0.src');
    expect($after)->not->toBe($before);

    [$width] = getimagesizefromstring($this->get($after)->assertOk()->getContent());
    expect($width)->toBe(192);
});

test('only the declared sizes are drawn', function () {
    $this->get('/pwa/icon-4000-any.png')->assertNotFound();
    $this->get('/pwa/icon-192-other.png')->assertNotFound();
});

test('the service worker caches nothing and is served from the root', function () {
    $sw = file_get_contents(public_path('sw.js'));

    expect($sw)->not->toContain('caches.')
        ->and($sw)->not->toContain("addEventListener('fetch'");
});

test('the app shell links the manifest', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('rel="manifest"', false)
        ->assertSee('name="theme-color"', false);
});
