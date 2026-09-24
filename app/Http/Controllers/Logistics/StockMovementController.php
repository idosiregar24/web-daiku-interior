<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\StockMovementRequest;
use App\Models\Material;
use App\Models\Project;
use App\Models\StockMovement;
use App\Services\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRD §4.8 "Manajemen Stok" — §7.1 "Material – Stok" row: CEO/PM read
 * (the ledger), Logistics records receipts and usage. The ledger is
 * append-only: no update/destroy actions exist.
 */
class StockMovementController extends Controller
{
    public function index(Request $request): Response
    {
        $movements = StockMovement::query()
            ->with(['material:id,name,unit', 'project:id,name', 'recorder:id,name'])
            ->byType($request->string('type')->value() ?: null)
            ->when($request->integer('material_id'), fn ($query, $id) => $query->where('material_id', $id))
            ->when($request->integer('project_id'), fn ($query, $id) => $query->where('project_id', $id))
            ->latest('movement_date')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Logistics/StockMovements/Index', [
            'movements' => $movements,
            'filters' => $request->only(['type', 'material_id', 'project_id']),
            'materials' => Material::orderBy('name')->get(['id', 'name']),
            'projects' => Project::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function stockIn(StockMovementRequest $request, Material $material, StockService $service): RedirectResponse
    {
        $service->stockIn($material, $request->validated(), $request->user());

        return back()->with('success', "Penerimaan {$material->name} dicatat.");
    }

    public function stockOut(StockMovementRequest $request, Material $material, StockService $service): RedirectResponse
    {
        $project = Project::findOrFail($request->validated('project_id'));

        $service->stockOut($material, $project, $request->validated(), $request->user());

        return back()->with('success', "Pemakaian {$material->name} untuk {$project->name} dicatat.");
    }
}
