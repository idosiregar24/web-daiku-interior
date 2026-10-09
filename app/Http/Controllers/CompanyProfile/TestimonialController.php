<?php

namespace App\Http\Controllers\CompanyProfile;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompanyProfile\SaveTestimonialRequest;
use App\Models\PortfolioItem;
use App\Models\Testimonial;
use App\Services\TestimonialService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Sprint 20 Sub 05 (K2) — ⚙ Pengaturan → Testimoni, CEO + Marketing (+ SUPERADMIN). */
class TestimonialController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Testimonials/Index', [
            'testimonials' => Testimonial::query()
                ->with('portfolioItem:id,title')
                ->ordered()
                ->get()
                ->map(fn (Testimonial $row) => [
                    ...$row->only(['id', 'client_label', 'quote', 'portfolio_item_id', 'is_published', 'sort_order']),
                    'portfolio_title' => $row->portfolioItem?->title,
                ]),
            'portfolioOptions' => PortfolioItem::query()->ordered()->get(['id', 'title'])
                ->map(fn (PortfolioItem $item) => ['value' => (string) $item->id, 'label' => $item->title]),
        ]);
    }

    public function store(SaveTestimonialRequest $request, TestimonialService $service): RedirectResponse
    {
        $service->create($request->validated(), $request->user());

        return back()->with('success', 'Testimoni ditambahkan.');
    }

    public function update(SaveTestimonialRequest $request, Testimonial $testimonial, TestimonialService $service): RedirectResponse
    {
        $service->update($testimonial, $request->validated(), $request->user());

        return back()->with('success', 'Testimoni disimpan.');
    }

    public function destroy(Request $request, Testimonial $testimonial, TestimonialService $service): RedirectResponse
    {
        $service->delete($testimonial, $request->user());

        return back()->with('success', 'Testimoni dihapus.');
    }
}
