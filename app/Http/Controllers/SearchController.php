<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchRequest;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Sprint 13 #12 / D5 — the topbar search ("Cari menu, proyek, klien…").
 * Every kind is searched only for the roles that may open its list page
 * (the same `role:` rows as routes/web.php), through the list's own scope
 * — an Asisten PM finds only their projects, a Tukang only the projects
 * they have a task on. The response is a whitelist (id, label, sublabel,
 * url) — never a raw model, and no money amounts.
 */
class SearchController extends Controller
{
    public const PER_KIND = 5;

    /** Who may search each kind = who may open its list page (`role:` in routes/web.php). */
    private const KIND_ROLES = [
        'projects' => ['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'ASISTEN_PM', 'QA', 'FINANCE', 'LOGISTICS', 'FIELD_STAFF'],
        'leads' => ['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM'],
        'quotations' => ['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'ASISTEN_PM', 'FINANCE'],
        // `module:hr` (ModuleAccessMiddleware).
        'employees' => ['CEO', 'HR'],
    ];

    public function __invoke(SearchRequest $request): JsonResponse
    {
        $user = $request->user();
        $like = '%'.addcslashes(trim($request->validated('q')), '\%_').'%';

        $groups = [];

        if ($this->may($user, 'projects')) {
            $groups[] = $this->group('projects', 'Proyek', Project::query()
                ->visibleTo($user)
                ->with('lead:id,client_name')
                ->where(fn (Builder $q) => $q->where('name', 'like', $like)
                    ->orWhereHas('lead', fn (Builder $lead) => $lead->where('client_name', 'like', $like)))
                ->latest()
                ->limit(self::PER_KIND)
                ->get(['id', 'name', 'lead_id', 'status'])
                ->map(fn (Project $project) => [
                    'id' => $project->id,
                    'label' => $project->name,
                    'sublabel' => $project->lead?->client_name,
                    'url' => route('projects.show', $project),
                ]));
        }

        if ($this->may($user, 'leads')) {
            $groups[] = $this->group('leads', 'Lead / Klien', Lead::query()
                ->where('client_name', 'like', $like)
                ->latest()
                ->limit(self::PER_KIND)
                ->get(['id', 'client_name', 'status'])
                ->map(fn (Lead $lead) => [
                    'id' => $lead->id,
                    'label' => $lead->client_name,
                    'sublabel' => 'Lead · '.Str::headline(strtolower($lead->status->value)),
                    'url' => route('crm.leads.show', $lead),
                ]));
        }

        if ($this->may($user, 'quotations')) {
            $groups[] = $this->group('quotations', 'Quotation', Quotation::query()
                ->with('lead:id,client_name')
                ->whereHas('lead', fn (Builder $lead) => $lead->where('client_name', 'like', $like))
                ->latest()
                ->limit(self::PER_KIND)
                ->get(['id', 'lead_id', 'type', 'custom_name', 'parent_quotation_id', 'version', 'status'])
                ->map(fn (Quotation $quotation) => [
                    'id' => $quotation->id,
                    'label' => $quotation->lead?->client_name ?? "Quotation #{$quotation->id}",
                    'sublabel' => "{$quotation->title()} v{$quotation->version}",
                    'url' => route('quotations.show', $quotation),
                ]));
        }

        if ($this->may($user, 'employees')) {
            $groups[] = $this->group('employees', 'Karyawan', Employee::query()
                ->hrEligible()
                ->with('position:id,name')
                ->where('name', 'like', $like)
                ->orderBy('name')
                ->limit(self::PER_KIND)
                ->get(['id', 'name', 'position_id', 'is_active'])
                ->map(fn (Employee $employee) => [
                    'id' => $employee->id,
                    'label' => $employee->name,
                    'sublabel' => trim(($employee->position?->name ?? 'Karyawan').($employee->is_active ? '' : ' · Nonaktif')),
                    'url' => route('hr.employees.show', $employee),
                ]));
        }

        return response()->json(['groups' => array_values(array_filter($groups))]);
    }

    private function may(User $user, string $kind): bool
    {
        return $user->hasAnyRole([...self::KIND_ROLES[$kind], 'SUPERADMIN']);
    }

    /**
     * @param  Collection<int, array{id: int, label: string, sublabel: string|null, url: string}>  $items
     * @return array{key: string, label: string, items: list<array{id: int, label: string, sublabel: string|null, url: string}>}|null
     */
    private function group(string $key, string $label, $items): ?array
    {
        return $items->isEmpty() ? null : ['key' => $key, 'label' => $label, 'items' => $items->values()->all()];
    }
}
