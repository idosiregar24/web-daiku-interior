<?php

namespace App\Http\Controllers\Logistics;

use App\Exports\AssetsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\StoreAssetRequest;
use App\Http\Requests\Logistics\UpdateAssetRequest;
use App\Models\Asset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * PRD §4.8 "Aset Inventaris" — §7.1 "Asset Inventory" row: CEO/PM/Finance
 * read, Logistics CRUD. Plain CRUD, no Service (skill step 4: lookup-style
 * modules don't need one).
 */
class AssetController extends Controller
{
    public function index(Request $request): Response
    {
        $assets = Asset::query()
            ->search($request->string('search')->value() ?: null)
            ->byCondition($request->string('condition')->value() ?: null)
            ->when($request->string('category')->value(), fn ($query, $category) => $query->where('category', $category))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Logistics/Assets/Index', [
            'assets' => $assets,
            'filters' => $request->only(['search', 'condition', 'category']),
            'categories' => Asset::whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'summary' => [
                'totalItems' => Asset::count(),
                'totalValue' => (float) Asset::sum('value'),
                'damagedCount' => Asset::where('condition', 'DAMAGED')->count(),
            ],
            'canManage' => $request->user()->hasAnyRole(['LOGISTICS', 'SUPERADMIN']),
        ]);
    }

    public function store(StoreAssetRequest $request): RedirectResponse
    {
        Asset::create($request->validated());

        return back()->with('success', 'Aset berhasil ditambahkan.');
    }

    public function update(UpdateAssetRequest $request, Asset $asset): RedirectResponse
    {
        $asset->update($request->validated());

        return back()->with('success', 'Aset berhasil diperbarui.');
    }

    public function destroy(Asset $asset): RedirectResponse
    {
        $asset->delete();

        return back()->with('success', 'Aset berhasil dihapus.');
    }

    public function exportExcel(): BinaryFileResponse
    {
        return Excel::download(new AssetsExport, 'daftar-aset-'.now()->format('Y-m-d').'.xlsx');
    }
}
