<?php

namespace App\Http\Controllers\Site;

use App\Enums\ProjectType;
use App\Http\Controllers\Controller;
use App\Models\PortfolioItem;
use App\Models\ServicePage;
use App\Services\RoleRedirectService;
use App\Support\CompanyProfile\ProfileContent;
use App\Support\CompanyProfile\ServiceCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * Sprint 20 — the public company profile (Blade, D2). Read-only: no form,
 * no POST (K1 — every contact is a WhatsApp link). The views get their
 * content from ProfileContent (`$profile`, shared by AppServiceProvider).
 * Everything except `/` runs without a session (routes/site.php), so the
 * pages can be cached and never set a cookie.
 */
class SiteController extends Controller
{
    public const PORTFOLIO_PER_PAGE = 12;

    /** `/`: guests see the company profile; signed-in staff go to their own first page. */
    public function home(Request $request, RoleRedirectService $roleRedirect): View|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route($roleRedirect->routeNameFor($request->user()));
        }

        return view('site.home');
    }

    /** `/app` — the PWA start_url (K3): login, or the role's page. Never the profile. */
    public function app(Request $request, RoleRedirectService $roleRedirect): RedirectResponse
    {
        return $request->user()
            ? redirect()->route($roleRedirect->routeNameFor($request->user()))
            : redirect()->route('login');
    }

    public function portfolioIndex(Request $request, ProfileContent $profile): View
    {
        $type = ProjectType::tryFrom(strtoupper($request->string('jenis')->value()));
        $entry = $type ? ServiceCatalog::forType($type) : null;

        $items = PortfolioItem::query()
            ->published()
            ->when($entry, fn ($query) => $query->ofTypes($entry->types))
            ->with(['city', 'cover', 'photos'])
            ->ordered()
            ->paginate(self::PORTFOLIO_PER_PAGE)
            ->withQueryString();

        return view('site.portfolio.index', [
            'items' => $items,
            'cards' => $items->getCollection()->map(fn (PortfolioItem $item) => $profile->portfolioCard($item))->all(),
            'activeEntry' => $entry,
            // Filter chips only for services that actually have published work.
            'filters' => collect(ServiceCatalog::all())
                ->filter(fn ($service) => PortfolioItem::query()->published()->ofTypes($service->types)->exists())
                ->values(),
        ]);
    }

    public function portfolioShow(string $slug, ProfileContent $profile): View
    {
        $item = PortfolioItem::query()
            ->published()
            ->where('slug', $slug)
            ->with(['city', 'photos'])
            ->firstOrFail();

        $entry = ServiceCatalog::forType($item->project_type);

        return view('site.portfolio.show', [
            'item' => $item,
            'photos' => $item->photos->map(fn ($photo) => $profile->photo($photo, $item->title))->all(),
            'entry' => $entry,
            'related' => PortfolioItem::query()
                ->published()
                ->whereKeyNot($item->id)
                ->when($entry, fn ($query) => $query->ofTypes($entry->types))
                ->with(['city', 'cover', 'photos'])
                ->ordered()
                ->limit(3)
                ->get()
                ->map(fn (PortfolioItem $related) => $profile->portfolioCard($related))
                ->all(),
        ]);
    }

    public function service(string $slug, ProfileContent $profile): View
    {
        $entry = ServiceCatalog::findBySlug($slug);
        abort_unless($entry !== null, 404);

        $page = $profile->servicePage($entry);
        abort_unless($page !== null, 404);

        return view('site.services.show', [
            'entry' => $entry,
            'page' => $page,
            'portfolio' => $profile->portfolio(6, $entry->types),
            'hasRealPortfolio' => PortfolioItem::query()->published()->ofTypes($entry->types)->exists(),
        ]);
    }

    public function sitemap(): Response
    {
        $home = collect([ServicePage::query()->max('updated_at'), PortfolioItem::query()->published()->max('updated_at')])->filter()->max();

        $urls = collect([
            ['loc' => route('site.home'), 'lastmod' => $home],
            ['loc' => route('site.portfolio.index'), 'lastmod' => PortfolioItem::query()->published()->max('updated_at')],
        ]);

        ServicePage::query()->where('is_published', true)->get()->each(function (ServicePage $page) use ($urls) {
            if ($entry = $page->entry()) {
                $urls->push(['loc' => $entry->url(), 'lastmod' => $page->updated_at]);
            }
        });

        PortfolioItem::query()->published()->ordered()->get(['slug', 'updated_at'])
            ->each(fn (PortfolioItem $item) => $urls->push(['loc' => route('site.portfolio.show', $item->slug), 'lastmod' => $item->updated_at]));

        return response()
            ->view('site.sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    /**
     * Allow the profile, disallow every path of the system. The list is
     * read from the route table (first segment of every route that isn't
     * part of the public site), so a new module is covered without
     * touching this file.
     */
    public function robots(): Response
    {
        $public = ['', 'portofolio', 'layanan', 'sitemap.xml', 'robots.txt', 'images', 'build', 'storage', 'branding'];

        $disallow = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteDefinition $route) => in_array('GET', $route->methods(), true))
            ->reject(fn (RouteDefinition $route) => str_starts_with((string) $route->getName(), 'site.'))
            ->map(fn (RouteDefinition $route) => explode('/', trim($route->uri(), '/'))[0])
            ->reject(fn (string $segment) => in_array($segment, $public, true) || str_starts_with($segment, '{'))
            // A rule is a prefix: never one that would also hide a public path.
            ->reject(fn (string $segment) => collect($public)->contains(fn (string $path) => $path !== '' && str_starts_with($path, $segment)))
            ->unique()
            ->sort()
            ->values();

        $lines = ['User-agent: *', 'Allow: /'];

        foreach ($disallow as $segment) {
            $lines[] = 'Disallow: /'.$segment;
        }

        $lines[] = '';
        $lines[] = 'Sitemap: '.route('site.sitemap');

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
