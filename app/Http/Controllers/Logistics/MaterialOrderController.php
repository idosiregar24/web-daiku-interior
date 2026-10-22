<?php

namespace App\Http\Controllers\Logistics;

use App\Enums\MaterialRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\DecideMaterialPlanRequest;
use App\Http\Requests\Logistics\MarkMaterialOrderedRequest;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\Unit;
use App\Models\Vendor;
use App\Services\MaterialRequestService;
use App\Services\ProjectMaterialService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 29 Sub 11 (E3/E4) — "Pemesanan Bahan": the Rencana Bahan the
 * Pengawas / Admin Proyek submitted, per project. Logistik approves or
 * rejects (custom lines through the catalog review), marks bought lines
 * "Dipesan" and records what arrives; the CEO and the projects' PM /
 * Asisten PM read along (ProjectMaterial::visibleTo()). Where a line stands
 * is ProjectMaterial::orderStatus() — never re-derived per page.
 */
class MaterialOrderController extends Controller
{
    private const STATUS_FILTERS = ['open', 'DIAJUKAN', 'DISETUJUI', 'DIPESAN', 'DITERIMA', 'DITOLAK', 'all'];

    public function index(Request $request, MaterialRequestService $requests): Response
    {
        $user = $request->user();
        $status = in_array($request->query('status'), self::STATUS_FILTERS, true) ? $request->query('status') : 'open';
        $canDecide = $user->hasAnyRole(['LOGISTICS', 'SUPERADMIN']);

        // A draft (DIRENCANAKAN) is still the Pengawas's — Logistik sees a plan once it is submitted.
        $scoped = fn () => ProjectMaterial::query()
            ->fromPlan()
            ->visibleTo($user)
            ->where('request_status', '!=', MaterialRequestStatus::Direncanakan->value);

        $lines = $this->filtered($scoped(), $status)
            ->with([
                'project:id,name,pm_id,status',
                'requester:id,name',
                'reviewer:id,name',
                'orderer:id,name',
                'unit:id,code,name',
                'material:id,code,name,unit_id,stock',
                'vendor:id,name',
            ])
            ->when($request->integer('project_id'), fn (Builder $q, int $id) => $q->where('project_id', $id))
            // A project's lines together, the oldest first.
            ->orderBy('project_id')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (ProjectMaterial $line) => [
                ...$line->toArray(),
                // The catalog review of a custom line (decision #13) — same dialog as Pengajuan Barang.
                'similar_catalog' => $canDecide && $line->material_id === null && $line->request_status === MaterialRequestStatus::Diajukan
                    ? $requests->similarCatalog($line)
                    : [],
                'similar_requests' => $canDecide && $line->material_id === null && $line->request_status === MaterialRequestStatus::Diajukan
                    ? $requests->similarRequests($line)
                    : [],
            ]);

        return Inertia::render('Logistics/MaterialOrders/Index', [
            'lines' => $lines,
            'filters' => ['status' => $status, 'project_id' => $request->integer('project_id') ?: null],
            'counts' => [
                'DIAJUKAN' => $this->filtered($scoped(), 'DIAJUKAN')->count(),
                'DISETUJUI' => $this->filtered($scoped(), 'DISETUJUI')->count(),
                'DIPESAN' => $this->filtered($scoped(), 'DIPESAN')->count(),
            ],
            'projects' => Project::query()
                ->whereIn('id', $scoped()->select('project_id'))
                ->orderBy('name')
                ->get(['id', 'name']),
            'permissions' => ['decide' => $canDecide],
            'vendors' => $canDecide ? Vendor::options() : [],
            'units' => $canDecide ? Unit::options() : [],
            'catalog' => $canDecide ? Material::active()->orderBy('name')->get(['id', 'code', 'name', 'unit_id', 'stock', 'cost_price']) : [],
            'categories' => $canDecide ? MaterialCategory::options() : [],
        ]);
    }

    public function decide(DecideMaterialPlanRequest $request, ProjectMaterialService $service): RedirectResponse
    {
        $lines = $service->decidePlan($request->validated('ids'), $request->validated('decision'), $request->validated(), $request->user());

        return back()->with('success', $request->validated('decision') === 'approve'
            ? "{$lines->count()} bahan disetujui."
            : "{$lines->count()} bahan ditolak.");
    }

    public function order(MarkMaterialOrderedRequest $request, ProjectMaterialService $service): RedirectResponse
    {
        $lines = $service->markOrdered($request->validated('ids'), $request->validated(), $request->user());

        return back()->with('success', "{$lines->count()} bahan ditandai dipesan.");
    }

    /**
     * The status filter in SQL — the same buckets as ProjectMaterial::orderStatus()
     * (DECIMAL(12,2) columns compared as such, never as PHP floats).
     */
    private function filtered(Builder $query, string $status): Builder
    {
        $approved = MaterialRequestStatus::Disetujui->value;

        return match ($status) {
            'open' => $query->where(fn (Builder $q) => $q
                ->where('request_status', MaterialRequestStatus::Diajukan->value)
                ->orWhere(fn (Builder $q) => $q->where('request_status', $approved)->whereColumn('qty_received', '<', 'qty_planned'))),
            'DIAJUKAN', 'DITOLAK' => $query->where('request_status', $status),
            'DISETUJUI' => $query->where('request_status', $approved)->whereNull('ordered_at')->where('qty_received', 0),
            'DIPESAN' => $query->where('request_status', $approved)->whereNotNull('ordered_at')->whereColumn('qty_received', '<', 'qty_planned'),
            'DITERIMA' => $query->where('request_status', $approved)->where('qty_received', '>', 0)->whereColumn('qty_received', '>=', 'qty_planned'),
            default => $query,
        };
    }
}
