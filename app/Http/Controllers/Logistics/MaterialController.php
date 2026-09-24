<?php

namespace App\Http\Controllers\Logistics;

use App\Enums\ProjectStatus;
use App\Exports\MaterialsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\StoreMaterialRequest;
use App\Http\Requests\Logistics\UpdateMaterialRequest;
use App\Models\Material;
use App\Models\Project;
use App\Services\LogisticsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * PRD §4.8 "Material Master" + "Margin Tracker" — §7.1 "Material –
 * Master" row: CEO/EST/PM read (Estimator reads prices for quotations),
 * Logistics CRUD.
 */
class MaterialController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $canManage = $user->hasAnyRole(['LOGISTICS', 'SUPERADMIN']);

        $materials = Material::query()
            ->search($request->string('search')->value() ?: null)
            ->byCategory($request->string('category')->value() ?: null)
            ->when($request->boolean('low_stock'), fn ($query) => $query->lowStock())
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        // "Analytics – Per Divisi" (PRD §7.1, P) for Logistics — the
        // division's headline numbers sit on its own main page.
        $summary = Material::query()
            ->selectRaw('COUNT(*) as total_items')
            ->selectRaw('COALESCE(SUM(stock * cost_price), 0) as stock_value')
            ->selectRaw('COALESCE(SUM(stock * (sell_price - cost_price)), 0) as potential_margin')
            ->first();

        return Inertia::render('Logistics/Materials/Index', [
            'materials' => $materials,
            'filters' => [
                'search' => $request->string('search')->value(),
                'category' => $request->string('category')->value(),
                'low_stock' => $request->boolean('low_stock'),
            ],
            'categories' => Material::whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'summary' => [
                'totalItems' => (int) $summary->total_items,
                'lowStockCount' => Material::lowStock()->count(),
                'stockValue' => (float) $summary->stock_value,
                'potentialMargin' => (float) $summary->potential_margin,
            ],
            'canManage' => $canManage,
            // Stock-out needs a project picker; only Logistics records usage.
            'projects' => $canManage
                ? Project::whereIn('status', [ProjectStatus::Active->value, ProjectStatus::OnHold->value])
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : [],
        ]);
    }

    public function store(StoreMaterialRequest $request): RedirectResponse
    {
        Material::create($request->validated());

        return back()->with('success', 'Material berhasil ditambahkan.');
    }

    public function update(UpdateMaterialRequest $request, Material $material): RedirectResponse
    {
        $material->update($request->validated());

        return back()->with('success', 'Material berhasil diperbarui.');
    }

    public function destroy(Material $material, LogisticsService $service): RedirectResponse
    {
        $service->deleteMaterial($material);

        return back()->with('success', 'Material berhasil dihapus.');
    }

    public function exportExcel(): BinaryFileResponse
    {
        return Excel::download(new MaterialsExport, 'daftar-material-'.now()->format('Y-m-d').'.xlsx');
    }
}
