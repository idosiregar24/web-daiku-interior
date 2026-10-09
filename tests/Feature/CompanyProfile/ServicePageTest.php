<?php

use App\Enums\ProjectType;
use App\Models\PortfolioItem;
use App\Models\ServicePage;
use App\Models\User;
use App\Support\CompanyProfile\ServiceCatalog;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ServicePageSeeder;
use Illuminate\Support\Str;

/*
 * Sprint 20 Sub 06 (K4) — one page per service, from ServiceCatalog.
 * Placeholder text can't be published; unpublished pages open but are
 * neither indexed nor in the sitemap.
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(ServicePageSeeder::class);
});

function servicePageUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function kitchenPage(): ServicePage
{
    return ServicePage::firstWhere('project_type', ProjectType::KitchenSet->value);
}

function ownText(ServicePage $page, array $overrides = []): array
{
    return [
        'title' => $page->title,
        'headline' => $page->headline,
        'intro' => 'Teks pembuka tulisan tim Daiku sendiri tentang kitchen set.',
        'body' => "## Bahan\nKami memakai multipleks dan HPL pilihan Anda.\n\n## Garansi\nTulisan asli.",
        'highlights' => ['Ukur langsung', ''],
        'faqs' => [['q' => 'Berapa lama?', 'a' => 'Sekitar tiga minggu.'], ['q' => '', 'a' => '']],
        'meta_description' => 'Kitchen set Pekanbaru dengan RAB rinci per item.',
        'is_published' => true,
        ...$overrides,
    ];
}

test('the catalog: every type but LAINNYA, TOKO and RETAIL_TOKO on one page, slugs with the home city', function () {
    $slugs = collect(ServiceCatalog::all())->pluck('slug');

    expect($slugs)->toContain('kitchen-set-pekanbaru', 'interior-cafe-pekanbaru', 'desain-arsitektur-pekanbaru')
        ->and(ServiceCatalog::forType(ProjectType::Lainnya))->toBeNull()
        ->and(ServiceCatalog::forType(ProjectType::RetailToko)->slug)->toBe(ServiceCatalog::forType(ProjectType::Toko)->slug)
        ->and(ServicePage::count())->toBe(count(ServiceCatalog::all()));
});

test('CEO and Marketing edit service pages; other roles are refused', function () {
    $page = kitchenPage();

    foreach (['CEO', 'MARKETING', 'SUPERADMIN'] as $role) {
        $this->actingAs(servicePageUser($role))->get(route('settings.service-pages.index'))->assertOk();
        $this->actingAs(servicePageUser($role))->get(route('settings.service-pages.edit', $page))->assertOk();
    }

    foreach (['PM', 'FINANCE', 'FIELD_STAFF'] as $role) {
        $this->actingAs(servicePageUser($role))->get(route('settings.service-pages.index'))->assertForbidden();
        $this->actingAs(servicePageUser($role))->put(route('settings.service-pages.update', $page), ownText($page))->assertForbidden();
    }
});

test('a page still on placeholder text cannot be published', function () {
    $page = kitchenPage();

    $this->actingAs(servicePageUser('MARKETING'))
        ->put(route('settings.service-pages.update', $page), ownText($page, ['intro' => $page->intro]))
        ->assertSessionHasErrors('is_published');

    expect($page->fresh()->is_published)->toBeFalse();
});

test('own text publishes the page; empty list rows are dropped', function () {
    $page = kitchenPage();

    $this->actingAs(servicePageUser('MARKETING'))
        ->put(route('settings.service-pages.update', $page), ownText($page))
        ->assertSessionHasNoErrors();

    $page->refresh();
    expect($page->is_published)->toBeTrue()
        ->and($page->highlights)->toBe(['Ukur langsung'])
        ->and($page->faqs)->toBe([['q' => 'Berapa lama?', 'a' => 'Sekitar tiga minggu.']]);
});

test('an unpublished page opens with noindex and stays out of the sitemap', function () {
    $this->get('/layanan/kitchen-set-pekanbaru')
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex, follow">', false)
        ->assertSee('Jasa Kitchen Set Pekanbaru');

    $this->get('/sitemap.xml')->assertDontSee('/layanan/kitchen-set-pekanbaru', false);
});

test('a published page is indexable, in the sitemap, and renders its own text safely', function () {
    $page = kitchenPage();
    $page->update([...ownText($page), 'body' => "## Bahan\n<script>alert(1)</script> Multipleks."]);

    $this->get('/layanan/kitchen-set-pekanbaru')
        ->assertOk()
        ->assertSee('<meta name="robots" content="index, follow">', false)
        ->assertSee('<h2 class="mt-12', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('FAQPage')
        ->assertSee('BreadcrumbList');

    $this->get('/sitemap.xml')->assertSee(route('site.services.show', 'kitchen-set-pekanbaru'), false);
});

test('the page shows published work of its own kind only', function () {
    $make = fn (string $title, string $type, bool $published) => tap(PortfolioItem::create([
        'title' => $title, 'slug' => Str::slug($title), 'project_type' => $type,
        'is_published' => $published, 'client_consent' => $published,
    ]), fn ($item) => $item->photos()->create(['path' => 'p.webp', 'path_thumb' => 'p-t.webp', 'width' => 800, 'height' => 600]));

    $make('Toko Baju Terbit', 'TOKO', true);
    $make('Retail Sepatu Terbit', 'RETAIL_TOKO', true);
    $make('Toko Draf', 'TOKO', false);
    $make('Cafe Terbit', 'CAFE', true);

    $this->get('/layanan/interior-toko-pekanbaru')
        ->assertOk()
        ->assertSee('Toko Baju Terbit')
        ->assertSee('Retail Sepatu Terbit')
        ->assertDontSee('Toko Draf')
        ->assertDontSee('Cafe Terbit');
});

test('an unknown service slug is a 404', function () {
    $this->get('/layanan/kitchen-set-dumai')->assertNotFound();
    $this->get('/layanan/lainnya-pekanbaru')->assertNotFound();
});

test('the home page links every service page', function () {
    $html = $this->get('/')->getContent();

    foreach (ServiceCatalog::all() as $entry) {
        expect($html)->toContain('href="'.$entry->url().'"');
    }
});
