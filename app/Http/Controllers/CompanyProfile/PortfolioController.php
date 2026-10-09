<?php

namespace App\Http\Controllers\CompanyProfile;

use App\Enums\ProjectType;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompanyProfile\StorePortfolioItemRequest;
use App\Http\Requests\CompanyProfile\UpdatePortfolioItemRequest;
use App\Http\Requests\CompanyProfile\UploadPortfolioPhotosRequest;
use App\Models\City;
use App\Models\PortfolioItem;
use App\Models\PortfolioPhoto;
use App\Models\Project;
use App\Services\PortfolioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 20 Sub 04 (K2) — ⚙ Pengaturan → Portofolio, CEO + Marketing
 * (+ SUPERADMIN). What the public site shows under /portofolio.
 */
class PortfolioController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->value();
        $search = $request->string('search')->trim()->value();

        $items = PortfolioItem::query()
            ->with(['city:id,name', 'cover', 'photos' => fn ($query) => $query->limit(1)])
            ->withCount('photos')
            ->when($search !== '', fn ($query) => $query->where('title', 'like', "%{$search}%"))
            ->when($request->string('type')->value(), fn ($query, $type) => $query->where('project_type', $type))
            ->when($status === 'published', fn ($query) => $query->where('is_published', true))
            ->when($status === 'draft', fn ($query) => $query->where('is_published', false))
            ->ordered()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (PortfolioItem $item) => [
                ...$item->only(['id', 'title', 'slug', 'year', 'is_published', 'client_consent', 'photos_count', 'sort_order']),
                'project_type' => $item->project_type->value,
                'type_label' => $item->project_type->label(),
                'place' => $item->placeLabel(),
                'cover_thumb_url' => $item->coverPhoto()?->thumb_url,
                'public_url' => $item->is_published ? route('site.portfolio.show', $item->slug) : null,
                'updated_at' => $item->updated_at,
            ]);

        return Inertia::render('Settings/Portfolio/Index', [
            'items' => $items,
            'filters' => $request->only(['search', 'type', 'status']),
            'projectTypes' => ProjectType::options(),
            'cities' => City::options(),
        ]);
    }

    public function store(StorePortfolioItemRequest $request, PortfolioService $service): RedirectResponse
    {
        $item = $service->create($request->validated(), $request->user());

        return redirect()->route('settings.portfolio.edit', $item)
            ->with('success', 'Portofolio dibuat. Unggah fotonya, lalu terbitkan.');
    }

    public function edit(PortfolioItem $portfolio): Response
    {
        $portfolio->load(['photos', 'project:id,name,status']);

        return Inertia::render('Settings/Portfolio/Edit', [
            'item' => [
                ...$portfolio->only(['id', 'title', 'slug', 'city_id', 'location_label', 'year', 'summary', 'description', 'cover_photo_id', 'is_published', 'client_consent', 'sort_order', 'published_at']),
                'project_type' => $portfolio->project_type->value,
                'public_url' => route('site.portfolio.show', $portfolio->slug),
                'project' => $portfolio->project?->only(['id', 'name']),
            ],
            'photos' => $portfolio->photos->map(fn (PortfolioPhoto $photo) => $photo->only(['id', 'url', 'thumb_url', 'width', 'height', 'alt', 'caption', 'sort_order']))->values(),
            'projectTypes' => ProjectType::options(),
            'cities' => City::options(),
            'maxFiles' => UploadPortfolioPhotosRequest::MAX_FILES,
        ]);
    }

    public function update(UpdatePortfolioItemRequest $request, PortfolioItem $portfolio, PortfolioService $service): RedirectResponse
    {
        $service->update($portfolio, $request->validated(), $request->user());

        return back()->with('success', 'Portofolio disimpan.');
    }

    public function destroy(Request $request, PortfolioItem $portfolio, PortfolioService $service): RedirectResponse
    {
        $service->delete($portfolio, $request->user());

        return redirect()->route('settings.portfolio.index')->with('success', 'Portofolio dihapus.');
    }

    public function publish(Request $request, PortfolioItem $portfolio, PortfolioService $service): RedirectResponse
    {
        $service->publish($portfolio, $request->user());

        return back()->with('success', 'Portofolio diterbitkan di situs.');
    }

    public function unpublish(Request $request, PortfolioItem $portfolio, PortfolioService $service): RedirectResponse
    {
        $service->unpublish($portfolio, $request->user());

        return back()->with('success', 'Portofolio ditarik dari situs.');
    }

    /** "Jadikan Portofolio" on a finished project — opens the new (or existing) draft. */
    public function fromProject(Request $request, Project $project, PortfolioService $service): RedirectResponse
    {
        $item = $service->createFromProject($project, $request->user());

        return redirect()->route('settings.portfolio.edit', $item)
            ->with('success', 'Draf portofolio dari proyek siap. Lengkapi foto dan persetujuan klien sebelum terbit.');
    }
}
