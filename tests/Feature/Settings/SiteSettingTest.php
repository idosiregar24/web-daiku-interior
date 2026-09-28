<?php

use App\Models\AuditLog;
use App\Models\SiteSetting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake(SiteSetting::DISK);
});

function settingsUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

test('CEO can view and update site settings', function () {
    $ceo = settingsUser('CEO');

    $this->actingAs($ceo)->get(route('settings.edit'))->assertOk();

    $this->actingAs($ceo)->put(route('settings.update'), [
        'site_name' => 'Daiku Interior Updated',
        'site_tagline' => 'ERP Internal',
        'login_headline' => 'Kelola proyek interior dengan tenang.',
        'company_email' => 'info@daikuinterior.com',
    ])->assertRedirect();

    $settings = SiteSetting::current();
    expect($settings->site_name)->toBe('Daiku Interior Updated')
        ->and($settings->site_tagline)->toBe('ERP Internal')
        ->and($settings->login_headline)->toBe('Kelola proyek interior dengan tenang.');
});

test('superadmin can view and update site settings', function () {
    $admin = settingsUser('SUPERADMIN');

    $this->actingAs($admin)->get(route('settings.edit'))->assertOk();

    $this->actingAs($admin)->put(route('settings.update'), [
        'site_name' => 'Daiku Interior via SuperAdmin',
    ])->assertRedirect();

    expect(SiteSetting::current()->site_name)->toBe('Daiku Interior via SuperAdmin');
});

test('other roles are forbidden from site settings and asset uploads', function (string $role) {
    $user = settingsUser($role);

    $this->actingAs($user)->get(route('settings.edit'))->assertForbidden();
    $this->actingAs($user)->post(route('settings.assets.store', ['asset' => 'logo']), [
        'file' => UploadedFile::fake()->image('logo.png', 200, 200),
    ])->assertForbidden();
    $this->actingAs($user)->delete(route('settings.assets.destroy', ['asset' => 'logo']))->assertForbidden();
})->with(['MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'QA', 'FINANCE', 'LOGISTICS', 'FIELD_STAFF']);

test('site settings is a singleton — repeated access reuses the same row', function () {
    $first = SiteSetting::current();
    $second = SiteSetting::current();

    expect($first->id)->toBe($second->id)
        ->and(SiteSetting::count())->toBe(1);
});

test('site name is required and text lengths are capped', function () {
    $this->actingAs(settingsUser('CEO'))->put(route('settings.update'), [
        'site_name' => '',
        'site_tagline' => str_repeat('a', 61),
        'login_headline' => str_repeat('a', 121),
    ])->assertSessionHasErrors(['site_name', 'site_tagline', 'login_headline']);
});

test('updating text settings is audited with only the changed fields', function () {
    $ceo = settingsUser('CEO');

    $this->actingAs($ceo)->put(route('settings.update'), [
        'site_name' => 'Daiku Baru',
        'company_phone' => '021-555',
    ]);

    $log = AuditLog::where('action', 'settings.updated')->sole();
    expect($log->user_id)->toBe($ceo->id)
        ->and($log->new_values)->toBe(['site_name' => 'Daiku Baru', 'company_phone' => '021-555'])
        ->and($log->old_values)->toBe(['site_name' => 'Daiku Interior', 'company_phone' => null]);
});

test('CEO can upload a logo, which is then served publicly', function () {
    $this->actingAs(settingsUser('CEO'))->post(route('settings.assets.store', ['asset' => 'logo']), [
        'file' => UploadedFile::fake()->image('logo.png', 256, 256),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $settings = SiteSetting::current();
    Storage::disk(SiteSetting::DISK)->assertExists($settings->logo_path);
    expect($settings->logo_url)->toContain('/branding/logo')
        ->and(AuditLog::where('action', 'settings.asset_uploaded')->count())->toBe(1);

    auth()->logout();
    $this->get(route('branding.show', ['asset' => 'logo']))
        ->assertOk()
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
});

test('replacing an asset deletes the previous file', function () {
    $ceo = settingsUser('CEO');

    $this->actingAs($ceo)->post(route('settings.assets.store', ['asset' => 'favicon']), [
        'file' => UploadedFile::fake()->image('favicon.png', 64, 64),
    ]);
    $firstPath = SiteSetting::current()->favicon_path;

    $this->actingAs($ceo)->post(route('settings.assets.store', ['asset' => 'favicon']), [
        'file' => UploadedFile::fake()->image('favicon-2.png', 64, 64),
    ]);
    $secondPath = SiteSetting::current()->favicon_path;

    expect($secondPath)->not->toBe($firstPath);
    Storage::disk(SiteSetting::DISK)->assertMissing($firstPath);
    Storage::disk(SiteSetting::DISK)->assertExists($secondPath);
});

test('uploads reject SVG, oversized files and a too-small login image', function () {
    $ceo = settingsUser('CEO');

    $this->actingAs($ceo)->post(route('settings.assets.store', ['asset' => 'logo']), [
        'file' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'),
    ])->assertSessionHasErrors('file');

    $this->actingAs($ceo)->post(route('settings.assets.store', ['asset' => 'logo']), [
        'file' => UploadedFile::fake()->image('logo.png', 256, 256)->size(3000),
    ])->assertSessionHasErrors('file');

    $this->actingAs($ceo)->post(route('settings.assets.store', ['asset' => 'login_image']), [
        'file' => UploadedFile::fake()->image('cover.jpg', 300, 300),
    ])->assertSessionHasErrors('file');

    expect(SiteSetting::current()->logo_path)->toBeNull()
        ->and(SiteSetting::current()->login_image_path)->toBeNull();
});

test('CEO can remove an uploaded asset', function () {
    $ceo = settingsUser('CEO');

    $this->actingAs($ceo)->post(route('settings.assets.store', ['asset' => 'login_image']), [
        'file' => UploadedFile::fake()->image('cover.jpg', 1200, 900),
    ]);
    $path = SiteSetting::current()->login_image_path;

    $this->actingAs($ceo)->delete(route('settings.assets.destroy', ['asset' => 'login_image']))->assertRedirect();

    expect(SiteSetting::current()->login_image_path)->toBeNull()
        ->and(AuditLog::where('action', 'settings.asset_removed')->count())->toBe(1);
    Storage::disk(SiteSetting::DISK)->assertMissing($path);
    $this->get(route('branding.show', ['asset' => 'login_image']))->assertNotFound();
});

test('unknown asset keys are not routable', function () {
    $this->actingAs(settingsUser('CEO'))
        ->post('/settings/assets/background', ['file' => UploadedFile::fake()->image('x.png')])
        ->assertNotFound();

    $this->get('/branding/background')->assertNotFound();
});

test('branding is shared with guests on the login page', function () {
    SiteSetting::current()->update(['site_name' => 'Daiku Custom', 'login_headline' => 'Halo tim!']);

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
        ->where('site.name', 'Daiku Custom')
        ->where('site.tagline', SiteSetting::DEFAULT_TAGLINE)
        ->where('site.loginHeadline', 'Halo tim!')
        ->where('site.logoUrl', null));
});

test('public self-registration is disabled', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
});
