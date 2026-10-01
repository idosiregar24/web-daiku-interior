<?php

namespace App\Http\Controllers\Logistics;

use App\Exports\AssetsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\StoreAssetRequest;
use App\Http\Requests\Logistics\UpdateAssetRequest;
use App\Models\Asset;
use App\Services\AssetInstallmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * PRD §4.8 "Aset Inventaris" — §7.1 "Asset Inventory" row: CEO/PM/Finance
 * read, Logistics CRUD. Writes go through AssetInstallmentService because
 * the form also carries the asset's installment plan (PRD §4.7 "Aset &
 * Cicilan"), whose rules depend on the payments Finance already recorded.
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

        $outstanding = Asset::query()->installmentOutstanding();

        return Inertia::render('Logistics/Assets/Index', [
            'assets' => $assets,
            'filters' => $request->only(['search', 'condition', 'category']),
            'categories' => Asset::whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'summary' => [
                'totalItems' => Asset::count(),
                'totalValue' => (float) Asset::sum('value'),
                'damagedCount' => Asset::where('condition', 'DAMAGED')->count(),
                // "Sisa Cicilan" across every asset still being paid off.
                'installmentRemaining' => (float) (clone $outstanding)->sum('total_install') - (float) (clone $outstanding)->sum('paid_install'),
                'installmentCount' => (clone $outstanding)->count(),
            ],
            'canManage' => $request->user()->hasAnyRole(['LOGISTICS', 'SUPERADMIN']),
        ]);
    }

    public function store(StoreAssetRequest $request, AssetInstallmentService $service): RedirectResponse
    {
        $service->createAsset($request->validated(), $request->user());

        return back()->with('success', 'Aset berhasil ditambahkan.');
    }

    public function update(UpdateAssetRequest $request, Asset $asset, AssetInstallmentService $service): RedirectResponse
    {
        $service->updateAsset($asset, $request->validated(), $request->user());

        return back()->with('success', 'Aset berhasil diperbarui.');
    }

    public function destroy(Asset $asset, AssetInstallmentService $service): RedirectResponse
    {
        $service->deleteAsset($asset);

        return back()->with('success', 'Aset berhasil dihapus.');
    }

    public function exportExcel(): BinaryFileResponse
    {
        return Excel::download(new AssetsExport, 'daftar-aset-'.now()->format('Y-m-d').'.xlsx');
    }
}
