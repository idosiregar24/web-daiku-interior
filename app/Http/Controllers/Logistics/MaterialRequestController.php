<?php

namespace App\Http\Controllers\Logistics;

use App\Enums\MaterialRequestStatus;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\PmMaterialRequestDecisionRequest;
use App\Http\Requests\Logistics\ReviewMaterialRequestRequest;
use App\Http\Requests\Logistics\StoreMaterialRequestRequest;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Services\MaterialRequestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 11 Sub 4 — "Pengajuan Barang". One page, scoped per role:
 * Logistics reviews every request (CEO reads along), a PM sees their
 * projects' requests and approves Tukang requests, a Tukang sees their
 * own. Rules live in MaterialRequestService; "on this project" in
 * ProjectMaterialPolicy.
 */
class MaterialRequestController extends Controller
{
    private const STATUS_FILTERS = ['pending', 'MENUNGGU_PM', 'DIAJUKAN', 'DISETUJUI', 'DITOLAK', 'all'];

    public function index(Request $request, MaterialRequestService $service): Response
    {
        $user = $request->user();
        $status = in_array($request->query('status'), self::STATUS_FILTERS, true) ? $request->query('status') : 'pending';
        $canReview = $user->hasAnyRole(['LOGISTICS', 'SUPERADMIN']);

        $scoped = fn () => ProjectMaterial::query()->requested()->visibleTo($user);

        $requests = $scoped()
            ->with([
                'project:id,name,pm_id,status',
                'requester:id,name',
                'pmReviewer:id,name',
                'reviewer:id,name',
                'unit:id,code,name',
                'material:id,name,unit_id',
                'vendor:id,name',
            ])
            ->when($status === 'pending', fn (Builder $q) => $q->pendingRequest())
            ->when(! in_array($status, ['pending', 'all'], true), fn (Builder $q) => $q->where('request_status', $status))
            ->when($request->integer('project_id'), fn (Builder $q, int $id) => $q->where('project_id', $id))
            // Oldest waiting first — the queue is worked front to back.
            ->orderByRaw('COALESCE(submitted_at, created_at) '.($status === 'pending' ? 'asc' : 'desc'))
            ->paginate(20)
            ->withQueryString()
            ->through(fn (ProjectMaterial $line) => [
                ...$line->toArray(),
                // Logistics' review screen: similar catalog items + similar requests elsewhere (decision #13).
                'similar_catalog' => $canReview && $line->request_status === MaterialRequestStatus::Diajukan
                    ? $service->similarCatalog($line)
                    : [],
                'similar_requests' => $canReview && $line->request_status === MaterialRequestStatus::Diajukan
                    ? $service->similarRequests($line)
                    : [],
            ]);

        $isTukang = $user->hasRole('FIELD_STAFF') && ! $user->hasAnyRole(['PM', 'ESTIMATOR', 'SUPERADMIN']);

        return Inertia::render('Logistics/MaterialRequests/Index', [
            'requests' => $requests,
            'filters' => ['status' => $status, 'project_id' => $request->integer('project_id') ?: null],
            'counts' => [
                'MENUNGGU_PM' => $scoped()->where('request_status', MaterialRequestStatus::MenungguPm->value)->count(),
                'DIAJUKAN' => $scoped()->where('request_status', MaterialRequestStatus::Diajukan->value)->count(),
            ],
            'permissions' => [
                'review' => $canReview,
                'pmDecide' => $user->hasAnyRole(['PM', 'SUPERADMIN']),
                'request' => $user->hasAnyRole(['ESTIMATOR', 'PM', 'FIELD_STAFF', 'SUPERADMIN']),
            ],
            'isTukang' => $isTukang,
            'requestProjects' => $this->requestableProjects($user),
            'units' => Unit::options(),
            'vendors' => $isTukang ? [] : Vendor::options(),
            'catalog' => $canReview ? Material::active()->orderBy('name')->get(['id', 'code', 'name', 'unit_id', 'stock', 'cost_price']) : [],
            'categories' => $canReview ? MaterialCategory::options() : [],
            // §5.5 Lapis 4 — "Mungkin maksud Anda" suggestions while a requester types.
            'catalogHints' => $user->hasAnyRole(['ESTIMATOR', 'PM', 'FIELD_STAFF', 'SUPERADMIN'])
                ? Material::active()->orderBy('name')->get(['id', 'code', 'name', 'unit_id'])
                : [],
        ]);
    }

    public function store(StoreMaterialRequestRequest $request, Project $project, MaterialRequestService $service): RedirectResponse
    {
        $this->authorize('request', [ProjectMaterial::class, $project]);

        $line = $service->submit($project, $request->validated(), $request->user());

        return back()->with('success', $line->request_status === MaterialRequestStatus::MenungguPm
            ? 'Pengajuan dikirim ke PM proyek untuk disetujui.'
            : 'Pengajuan dikirim ke Logistik.');
    }

    public function pmDecision(PmMaterialRequestDecisionRequest $request, ProjectMaterial $projectMaterial, MaterialRequestService $service): RedirectResponse
    {
        $this->authorize('pmDecide', $projectMaterial);

        $service->pmDecide($projectMaterial, $request->validated('decision'), $request->validated('reason'), $request->user());

        return back()->with('success', $request->validated('decision') === 'approve'
            ? 'Pengajuan disetujui dan diteruskan ke Logistik.'
            : 'Pengajuan ditolak.');
    }

    public function review(ReviewMaterialRequestRequest $request, ProjectMaterial $projectMaterial, MaterialRequestService $service): RedirectResponse
    {
        $this->authorize('review', $projectMaterial);

        $line = $service->review($projectMaterial, $request->validated(), $request->user());

        return back()->with('success', $line->request_status === MaterialRequestStatus::Ditolak
            ? 'Pengajuan ditolak.'
            : 'Pengajuan disetujui — baris material proyek siap diproses.');
    }

    /** Running projects this user may raise a request on (mirrors ProjectMaterialPolicy::request()). */
    private function requestableProjects(User $user): array
    {
        if (! $user->hasAnyRole(['ESTIMATOR', 'PM', 'ASISTEN_PM', 'FIELD_STAFF', 'SUPERADMIN'])) {
            return [];
        }

        return Project::query()
            ->whereIn('status', [ProjectStatus::Active->value, ProjectStatus::OnHold->value])
            ->when(! $user->hasAnyRole(['ESTIMATOR', 'SUPERADMIN']), fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->when($user->hasRole('PM'), fn (Builder $q) => $q->orWhere('pm_id', $user->id))
                ->when($user->hasRole('ASISTEN_PM'), fn (Builder $q) => $q->orWhere('assistant_pm_id', $user->id))
                ->when($user->hasRole('FIELD_STAFF'), fn (Builder $q) => $q->orWhereHas('tasks', fn (Builder $t) => $t->where('assignee_id', $user->id)))))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }
}
