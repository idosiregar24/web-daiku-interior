<?php

namespace App\Http\Controllers\Logistics;

use App\Enums\ProjectStatus;
use App\Exports\MaterialsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\StoreMaterialRequest;
use App\Http\Requests\Logistics\UpdateMaterialRequest;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\Project;
use App\Models\Unit;
use App\Services\LogisticsService;
use App\Services\MaterialCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * PRD §4.8 "Material Master" + "Margin Tracker" — §7.1 "Material –
 * Master" row: CEO/EST/PM read (Estimator reads prices for quotations),
 * Logistics CRUD. New items go through MaterialCatalogService's
 * anti-duplicate rules (Sprint 11 §5.5).
 */
class MaterialController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $canManage = $user->hasAnyRole(['LOGISTICS', 'SUPERADMIN']);

        $materials = Material::query()
            ->with('category:id,name,code_prefix')
            // Merged items stay as records but aren't part of the working catalog.
            ->when(! $request->boolean('show_merged'), fn ($query) => $query->active())
            ->search($request->string('search')->value() ?: null)
            ->byCategory($request->integer('category_id') ?: null)
            ->when($request->boolean('low_stock'), fn ($query) => $query->lowStock())
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        // "Analytics – Per Divisi" (PRD §7.1, P) for Logistics — the
        // division's headline numbers sit on its own main page.
        $summary = Material::query()
            ->active()
            ->selectRaw('COUNT(*) as total_items')
            ->selectRaw('COALESCE(SUM(stock * cost_price), 0) as stock_value')
            ->selectRaw('COALESCE(SUM(stock * (sell_price - cost_price)), 0) as potential_margin')
            ->first();

        return Inertia::render('Logistics/Materials/Index', [
            'materials' => $materials,
            'filters' => [
                'search' => $request->string('search')->value(),
                'category_id' => $request->integer('category_id') ?: null,
                'low_stock' => $request->boolean('low_stock'),
                'show_merged' => $request->boolean('show_merged'),
            ],
            'categories' => MaterialCategory::query()->ordered()->get(['id', 'name', 'code_prefix', 'is_active']),
            'summary' => [
                'totalItems' => (int) $summary->total_items,
                'lowStockCount' => Material::active()->lowStock()->count(),
                'stockValue' => (float) $summary->stock_value,
                'potentialMargin' => (float) $summary->potential_margin,
                'possibleDuplicates' => Material::active()->where('possible_duplicate', true)->count(),
            ],
            'canManage' => $canManage,
            // Stock-out needs a project picker; only Logistics records usage.
            'projects' => $canManage
                ? Project::whereIn('status', [ProjectStatus::Active->value, ProjectStatus::OnHold->value])
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : [],
            'units' => $canManage ? Unit::options() : [],
        ]);
    }

    public function store(StoreMaterialRequest $request, MaterialCatalogService $catalog): RedirectResponse
    {
        $material = $catalog->create($request->validated(), $request->user());

        return back()->with('success', "Material {$material->code} berhasil ditambahkan.");
    }

    public function update(UpdateMaterialRequest $request, Material $material, MaterialCatalogService $catalog): RedirectResponse
    {
        $catalog->update($material, $request->validated());

        return back()->with('success', 'Material berhasil diperbarui.');
    }

    public function destroy(Material $material, LogisticsService $service): RedirectResponse
    {
        $service->deleteMaterial($material);

        return back()->with('success', 'Material berhasil dihapus.');
    }

    /**
     * §5.5 Lapis 3/4 — "Barang serupa sudah ada" while a new item is being
     * typed (Logistics' material form, request review). JSON, not an
     * Inertia visit: it only reads, and runs on every pause in typing.
     */
    public function similar(Request $request, MaterialCatalogService $catalog): JsonResponse
    {
        $identity = $request->validate([
            'material_category_id' => ['nullable', 'integer'],
            'base_name' => ['nullable', 'string', 'max:150'],
            'spec' => ['nullable', 'string', 'max:150'],
            'brand' => ['nullable', 'string', 'max:100'],
            'unit_id' => ['nullable', 'integer'],
            'exclude_id' => ['nullable', 'integer'],
        ]);

        $similar = $catalog->findSimilar($identity, $identity['exclude_id'] ?? null);
        $complete = filled($identity['material_category_id'] ?? null) && filled($identity['unit_id'] ?? null);
        $exactKey = $complete && filled($identity['base_name'] ?? null) ? $catalog->matchKey($identity) : null;
        $exact = $exactKey
            ? Material::query()->where('match_key', $exactKey)->when($identity['exclude_id'] ?? null, fn ($q, $id) => $q->whereKeyNot($id))->value('id')
            : null;

        return response()->json([
            'exact_id' => $exact,
            'items' => $similar->map(fn (Material $material) => [
                ...$material->only(['id', 'code', 'name', 'stock', 'cost_price', 'unit_id', 'similarity']),
                'unit' => $material->unit?->only(['id', 'code', 'name']),
                'category' => $material->category?->name,
            ]),
        ]);
    }

    /** §5.5 Lapis 6 — "Cek Duplikat": flagged pairs + a manual merge of any two items. */
    public function duplicates(MaterialCatalogService $catalog): Response
    {
        return Inertia::render('Logistics/Materials/Duplicates', [
            'groups' => $catalog->duplicateGroups(),
            'materials' => Material::query()->active()->orderBy('name')->get(['id', 'code', 'name', 'unit_id', 'stock']),
        ]);
    }

    public function merge(Request $request, Material $material, MaterialCatalogService $catalog): RedirectResponse
    {
        $data = $request->validate(
            ['target_id' => ['required', 'integer', Rule::exists('materials', 'id')->where('is_active', true)]],
            ['target_id.required' => 'Pilih barang tujuan.', 'target_id.exists' => 'Barang tujuan tidak ditemukan atau sudah nonaktif.'],
        );

        $into = $catalog->merge($material, Material::findOrFail($data['target_id']), $request->user());

        return back()->with('success', "{$material->code} digabung ke {$into->code} {$into->name}.");
    }

    public function exportExcel(): BinaryFileResponse
    {
        return Excel::download(new MaterialsExport, 'daftar-material-'.now()->format('Y-m-d').'.xlsx');
    }
}
