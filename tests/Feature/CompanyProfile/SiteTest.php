<?php

use App\Models\SiteSetting;
use App\Models\User;
use App\Support\CompanyProfile\Placeholder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ServicePageSeeder;

/*
 * Sprint 20 Sub 01–03 — the company profile at `/`: guests see it, staff
 * are still sent into the app; `/app` is the PWA's door; the system's
 * pages stay out of Google; the public pages never set a cookie.
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(ServicePageSeeder::class);
});

function siteUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

test('a guest opening / sees the company profile', function () {
    $response = $this->get('/')
        ->assertOk()
        ->assertViewIs('site.home')
        ->assertSee(Placeholder::heroHeadline('Pekanbaru'))
        ->assertSee('application/ld+json', false)
        ->assertSee('HomeAndConstructionBusiness');

    // Exactly one H1, no form anywhere (K1: WhatsApp only), no link into the system.
    expect(substr_count($response->getContent(), '<h1'))->toBe(1)
        ->and($response->getContent())->not->toContain('<form')
        ->and($response->getContent())->not->toContain('href="'.route('login').'"');
});

test('signed-in staff opening / still land on their role page', function () {
    $this->actingAs(siteUser('MARKETING'))->get('/')->assertRedirect(route('crm.dashboard'));
    $this->actingAs(siteUser('FIELD_STAFF'))->get('/')->assertRedirect(route('today.index'));
});

test('/app sends guests to the login and staff to their role page', function () {
    $this->get('/app')->assertRedirect(route('login'));
    $this->actingAs(siteUser('FINANCE'))->get('/app')->assertRedirect(route('finance.dashboard'));
});

test('the installed app starts at /app, not at the company profile', function () {
    expect($this->get(route('pwa.manifest'))->json('start_url'))->toBe('/app')
        ->and($this->get(route('pwa.manifest'))->json('scope'))->toBe('/');
});

test('every contact button opens WhatsApp with the company number and a website greeting', function () {
    SiteSetting::current()->update(['site_name' => 'Daiku Interior', 'company_phone' => '0811 759 7766']);

    $html = $this->get('/')->getContent();

    expect($html)->toContain('https://wa.me/628117597766?text=')
        ->and($html)->toContain(rawurlencode('Halo Daiku Interior, saya melihat website Anda dan ingin konsultasi.'))
        ->and($html)->toContain('rel="noopener"');
});

test('the WhatsApp number of Profil Publik wins over the company phone', function () {
    SiteSetting::current()->update(['company_phone' => '0811 759 7766', 'whatsapp_phone' => '081299990000']);

    expect($this->get('/')->getContent())->toContain('https://wa.me/6281299990000?text=');
});

test('the system pages carry noindex, the company profile does not', function () {
    $this->get(route('login'))->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->actingAs(siteUser('CEO'))->get(route('inbox.index'))->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    auth()->logout();
    $this->get('/')->assertHeaderMissing('X-Robots-Tag');
});

test('the public pages are cacheable and set no cookie', function () {
    $response = $this->get(route('site.portfolio.index'))->assertOk();

    expect($response->headers->getCookies())->toBe([])
        ->and($response->headers->get('Cache-Control'))->toContain('public')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=300');
});

test('robots.txt allows the profile and disallows the system paths read from the routes', function () {
    $robots = $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->getContent();

    expect($robots)->toContain("Allow: /\n")
        ->and($robots)->toContain('Disallow: /login')
        ->and($robots)->toContain('Disallow: /crm')
        ->and($robots)->toContain('Disallow: /penawaran')
        ->and($robots)->toContain('Disallow: /settings')
        ->and($robots)->toContain('Sitemap: '.route('site.sitemap'))
        ->and($robots)->not->toContain('Disallow: /portofolio')
        ->and($robots)->not->toContain('Disallow: /layanan')
        ->and($robots)->not->toContain("Disallow: /\n");
});

test('the sitemap lists the home page and the portfolio index', function () {
    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee('<loc>'.route('site.home').'</loc>', false)
        ->assertSee('<loc>'.route('site.portfolio.index').'</loc>', false);
});

test('with placeholders off, empty sections disappear instead of showing stand-ins', function () {
    config(['daiku.company_profile.placeholders' => false]);

    $this->get('/')
        ->assertOk()
        ->assertDontSee(Placeholder::TESTIMONIALS[0]['quote'])
        ->assertDontSee(Placeholder::PORTFOLIO[0]['title'])
        ->assertDontSee('judul-testimoni');
});

test('the Search Console code from Profil Publik is printed as a meta tag', function () {
    SiteSetting::current()->update(['google_site_verification' => 'abcDEF123_-x']);

    $this->get('/')->assertSee('<meta name="google-site-verification" content="abcDEF123_-x">', false);
});

test('an Inertia visit to / is turned into a full page load, not shown in a modal', function () {
    $this->get('/', ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('site.home'));
});

test('signing out from the app lands on the login page', function () {
    $this->actingAs(siteUser('CEO'))
        ->post(route('logout'), [], ['X-Inertia' => 'true'])
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
