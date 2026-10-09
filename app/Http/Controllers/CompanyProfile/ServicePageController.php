<?php

namespace App\Http\Controllers\CompanyProfile;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompanyProfile\UpdateServicePageRequest;
use App\Http\Requests\CompanyProfile\UploadServicePageHeroRequest;
use App\Models\PortfolioItem;
use App\Models\ServicePage;
use App\Services\ServicePageService;
use App\Support\CompanyProfile\Placeholder;
use App\Support\CompanyProfile\ServiceCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 20 Sub 06 (K4) — ⚙ Pengaturan → Halaman Layanan, CEO + Marketing
 * (+ SUPERADMIN). One row per ServiceCatalog entry; no create/delete —
 * the catalog decides which pages exist.
 */
class ServicePageController extends Controller
{
    public function index(ServicePageService $service): Response
    {
        $service->syncCatalog();

        $pages = ServicePage::query()->get()->keyBy(fn (ServicePage $page) => $page->project_type->value);

        return Inertia::render('Settings/ServicePages/Index', [
            'pages' => collect(ServiceCatalog::all())->map(function ($entry) use ($pages, $service) {
                $page = $pages[$entry->type->value];

                return [
                    'id' => $page->id,
                    'title' => $page->title,
                    'slug' => $page->slug,
                    'types' => array_map(fn ($type) => $type->label(), $entry->types),
                    'is_published' => $page->is_published,
                    'still_placeholder' => $service->stillPlaceholder($page),
                    'has_hero' => $page->hero_image !== null,
                    'portfolio_count' => PortfolioItem::query()->published()->ofTypes($entry->types)->count(),
                    'public_url' => $entry->url(),
                    'updated_at' => $page->updated_at,
                ];
            })->values(),
        ]);
    }

    public function edit(ServicePage $servicePage, ServicePageService $service): Response
    {
        $entry = $servicePage->entry();
        abort_unless($entry !== null, 404);

        return Inertia::render('Settings/ServicePages/Edit', [
            'page' => [
                ...$servicePage->only(['id', 'slug', 'title', 'headline', 'intro', 'body', 'meta_description', 'is_published']),
                'highlights' => $servicePage->highlights ?? [],
                'faqs' => $servicePage->faqs ?? [],
                'hero_url' => $servicePage->heroUrl(),
                'public_url' => $entry->url(),
                'types' => array_map(fn ($type) => $type->label(), $entry->types),
                'still_placeholder' => $service->stillPlaceholder($servicePage),
            ],
            // The seeded text, so the form can show what still has to change.
            'placeholder' => Placeholder::servicePage($entry),
            'maxFaqs' => ServicePage::MAX_FAQS,
        ]);
    }

    public function update(UpdateServicePageRequest $request, ServicePage $servicePage, ServicePageService $service): RedirectResponse
    {
        $service->update($servicePage, $request->validated(), $request->user());

        return back()->with('success', 'Halaman layanan disimpan.');
    }

    public function storeHero(UploadServicePageHeroRequest $request, ServicePage $servicePage, ServicePageService $service): RedirectResponse
    {
        $service->storeHero($servicePage, $request->file('file'), $request->user());

        return back()->with('success', 'Foto halaman layanan diunggah.');
    }

    public function destroyHero(Request $request, ServicePage $servicePage, ServicePageService $service): RedirectResponse
    {
        $service->deleteHero($servicePage, $request->user());

        return back()->with('success', 'Foto halaman layanan dihapus.');
    }
}
