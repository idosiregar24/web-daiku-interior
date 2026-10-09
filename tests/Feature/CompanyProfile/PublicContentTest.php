<?php

use App\Models\SiteSetting;
use App\Models\Testimonial;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ServicePageSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Sprint 20 Sub 05 — Profil Publik (CEO + SuperAdmin, like Pengaturan
 * Situs) and Testimoni (CEO + Marketing): what is saved shows on `/`.
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake(SiteSetting::DISK);
});

function contentUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

test('the CEO edits the public profile text and it shows on /', function () {
    $this->actingAs(contentUser('CEO'))->put(route('settings.public-profile.update'), [
        'hero_headline' => 'Interior Rapi untuk Rumah di Pekanbaru',
        'hero_subheadline' => 'Kami gambar, kami hitung, kami pasang.',
        'about_text' => "Paragraf pertama.\n\nParagraf kedua.",
        'founded_year' => 2018,
        'stat_projects' => 240,
        'stat_cities' => 5,
        'whatsapp_phone' => '+62 812-3456-7890',
        'opening_hours' => 'Senin–Sabtu, 08.00–17.00 WIB',
    ])->assertSessionHasNoErrors();

    $settings = SiteSetting::current();
    expect($settings->whatsapp_phone)->toBe('081234567890');

    auth()->logout();
    $html = $this->get('/')
        ->assertSee('Interior Rapi untuk Rumah di Pekanbaru')
        ->assertSee('Kami gambar, kami hitung, kami pasang.')
        ->assertSee('Paragraf kedua.')
        ->assertSee('240+')
        ->assertSee('2018')
        ->getContent();

    expect($html)->toContain('https://wa.me/6281234567890')
        ->and($html)->toContain('"openingHours":"Mo-Sa 08:00-17:00"');
});

test('only a Google Maps embed URL is accepted, also when pasted as a whole iframe', function () {
    $ceo = contentUser('CEO');

    $this->actingAs($ceo)->put(route('settings.public-profile.update'), [
        'maps_embed_url' => 'https://evil.example.com/maps/embed?pb=1',
    ])->assertSessionHasErrors('maps_embed_url');

    $this->actingAs($ceo)->put(route('settings.public-profile.update'), [
        'maps_embed_url' => '<iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12" width="600" height="450"></iframe>',
    ])->assertSessionHasNoErrors();

    expect(SiteSetting::current()->maps_embed_url)->toBe('https://www.google.com/maps/embed?pb=!1m18!1m12');

    auth()->logout();
    $this->get('/')->assertSee('src="https://www.google.com/maps/embed?pb=!1m18!1m12"', false);
});

test('the public profile is CEO and SuperAdmin only', function (string $role) {
    $this->actingAs(contentUser($role))
        ->put(route('settings.public-profile.update'), ['hero_headline' => 'X'])
        ->assertForbidden();
})->with(['MARKETING', 'PM', 'FINANCE']);

test('the hero photo is stored as WebP and shown on /', function () {
    $this->actingAs(contentUser('CEO'))->post(route('settings.assets.store', ['asset' => 'hero_image']), [
        'file' => UploadedFile::fake()->image('hero.jpg', 1600, 2000),
    ])->assertSessionHasNoErrors();

    $settings = SiteSetting::current();
    expect($settings->hero_image_path)->toEndWith('.webp');

    [$width, $height] = getimagesizefromstring(Storage::disk(SiteSetting::DISK)->get($settings->hero_image_path));
    expect([$width, $height])->toBe([1600, 2000]);

    auth()->logout();
    $this->get('/')->assertSee($settings->hero_image_url, false)->assertDontSee('hero-ruang-tamu.webp', false);
    $this->get($settings->hero_image_url)->assertOk();
});

test('Marketing and the CEO manage testimonials; others cannot', function () {
    $marketing = contentUser('MARKETING');

    $this->actingAs($marketing)->get(route('settings.testimonials.index'))->assertOk();
    $this->actingAs($marketing)->post(route('settings.testimonials.store'), [
        'client_label' => 'Ibu D., Kitchen Set — Panam',
        'quote' => 'Rapi dan tepat waktu.',
        'is_published' => true,
    ])->assertSessionHasNoErrors();

    $this->actingAs(contentUser('PM'))->get(route('settings.testimonials.index'))->assertForbidden();
    $this->actingAs(contentUser('FIELD_STAFF'))->post(route('settings.testimonials.store'), [
        'client_label' => 'X', 'quote' => 'Y',
    ])->assertForbidden();

    expect(Testimonial::count())->toBe(1);
});

test('only published testimonials replace the placeholders', function () {
    Testimonial::create(['client_label' => 'Bapak T., Cafe', 'quote' => 'Kutipan terbit.', 'is_published' => true]);
    Testimonial::create(['client_label' => 'Ibu S., Kamar', 'quote' => 'Kutipan draf.', 'is_published' => false]);

    $this->get('/')
        ->assertSee('Kutipan terbit.')
        ->assertDontSee('Kutipan draf.')
        ->assertDontSee('Yang paling membantu justru RAB-nya');
});

test('an uploaded logo replaces the default mark on the public site', function () {
    $this->seed(ServicePageSeeder::class);
    $this->get('/')->assertSee('bg-daiku-yellow text-base font-semibold', false);

    $this->actingAs(contentUser('CEO'))->post(route('settings.assets.store', ['asset' => 'logo']), [
        'file' => UploadedFile::fake()->image('logo.png', 400, 120),
    ])->assertSessionHasNoErrors();

    auth()->logout();
    $logoUrl = SiteSetting::current()->logo_url;

    foreach (['/', route('site.portfolio.index'), route('site.services.show', 'kitchen-set-pekanbaru')] as $url) {
        $this->get($url)
            ->assertSee('<img src="'.$logoUrl.'" alt="'.SiteSetting::current()->site_name.'"', false)
            ->assertDontSee('bg-daiku-yellow text-base font-semibold', false);
    }
});
