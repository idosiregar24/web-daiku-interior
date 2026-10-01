<?php

namespace App\Http\Controllers\CRM;

use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CRM\ConfirmLeadDealRequest;
use App\Http\Requests\CRM\StoreLeadRequest;
use App\Http\Requests\CRM\UpdateLeadRequest;
use App\Http\Requests\CRM\UpdateLeadStatusRequest;
use App\Models\Lead;
use App\Models\LeadCategory;
use App\Models\LeadSource;
use App\Models\PipelineLog;
use App\Models\User;
use App\Services\LeadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class LeadController extends Controller
{
    public function index(Request $request): Response
    {
        $leads = Lead::query()
            ->with([
                'assignee:id,name',
                'creator:id,name',
                'design:id,lead_id',
                // Row actions "Konfirmasi Deal" / "Klien Menolak Penawaran"
                // need the offer's state and validity.
                'quotation:id,lead_id,status,valid_until,version',
                'leadSource:id,name',
                'leadCategory:id,name',
            ])
            ->byStatus($request->string('status')->value() ?: null)
            ->byPriority($request->string('priority')->value() ?: null)
            ->byLeadSource($request->integer('lead_source_id') ?: null)
            ->byLeadCategory($request->integer('lead_category_id') ?: null)
            ->when($request->filled('search'), fn ($query) => $query->where(
                'client_name',
                'like',
                '%'.$request->string('search').'%',
            ))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('CRM/Index', [
            'leads' => $leads,
            'filters' => $request->only(['status', 'priority', 'search', 'lead_source_id', 'lead_category_id']),
            'marketers' => User::role('MARKETING')->orderBy('name')->get(['id', 'name']),
            'projectManagers' => User::role('PM')->orderBy('name')->get(['id', 'name']),
            'designers' => User::role('DESIGNER')->orderBy('name')->get(['id', 'name']),
            // Sumber Lead / Kategori Customer — Data Master lists
            // (SuperAdmin-editable, see MasterData\LeadSourceController /
            // LeadCategoryController). The form submits the row `id`
            // (`lead_source_id` / `lead_category_id`); LeadService keeps the
            // legacy `source`/`category` strings in sync.
            'leadSources' => LeadSource::orderBy('name')->get(['id', 'name']),
            'leadCategories' => LeadCategory::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Lead detail — client data, the downstream Design/Quotation/Project it
     * turned into, and the pipeline history. Same read roles as index();
     * the history itself follows PRD §7.1's narrower "CRM – Pipeline Log"
     * row (CEO/MKT/PM only — Designer and Estimator see the lead, not its
     * log).
     */
    public function show(Request $request, Lead $lead): Response
    {
        $user = $request->user();
        $canManage = $user->hasAnyRole(['CEO', 'MARKETING', 'SUPERADMIN']);
        $canViewPipelineLog = $user->hasAnyRole(['CEO', 'MARKETING', 'PM', 'SUPERADMIN']);
        $canOpenDesign = $user->hasAnyRole(['DESIGNER', 'SUPERADMIN']);

        $lead->load([
            'assignee:id,name',
            'creator:id,name',
            'leadSource:id,name',
            'leadCategory:id,name',
            'design:id,lead_id,pic_id,status,deadline,client_acc',
            'design.pic:id,name',
            'quotation:id,lead_id,status,total_amount,version,valid_until',
            'project:id,lead_id,name,pm_id,status,contract_value',
            'project.pm:id,name',
        ]);

        return Inertia::render('CRM/Show', [
            'lead' => $lead,
            // Flattened instead of serializing the `changedBy` relation —
            // it snake_cases to `changed_by` and would overwrite the FK
            // column of the same name (see Lead::assignee()).
            'pipelineLogs' => $canViewPipelineLog
                ? $lead->pipelineLogs()
                    ->with('changedBy:id,name')
                    ->orderByDesc('id')
                    ->get()
                    ->map(fn (PipelineLog $log) => [
                        'id' => $log->id,
                        'from_status' => $log->from_status,
                        'to_status' => $log->to_status,
                        'note' => $log->note,
                        'changed_by_name' => $log->changedBy?->name,
                        'created_at' => $log->created_at,
                    ])
                : null,
            'canManage' => $canManage,
            'canOpenDesign' => $canOpenDesign,
            // Option lists for the edit/deal/design dialogs — only sent to
            // roles that can open them.
            'marketers' => $canManage ? User::role('MARKETING')->orderBy('name')->get(['id', 'name']) : [],
            'projectManagers' => $canManage ? User::role('PM')->orderBy('name')->get(['id', 'name']) : [],
            'designers' => $canOpenDesign ? User::role('DESIGNER')->orderBy('name')->get(['id', 'name']) : [],
            'leadSources' => $canManage ? LeadSource::orderBy('name')->get(['id', 'name']) : [],
            'leadCategories' => $canManage ? LeadCategory::orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    /**
     * "Pipeline dashboard Marketing: funnel chart + statistik lead"
     * (.claude/plan/sprint-03.md Week 5). Deliberately scoped smaller than
     * PRD §4.10's CEO Executive Dashboard (Analytics module, Sprint 5/6) —
     * this is the "Analytics – Per Divisi" partial view PRD §7.1 describes
     * for non-CEO roles, not the full executive one.
     */
    public function dashboard(Request $request): Response
    {
        $counts = Lead::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $funnel = collect([LeadStatus::FollowUp, LeadStatus::DealDesain, LeadStatus::Closing])
            ->map(fn (LeadStatus $status) => [
                'status' => $status->value,
                'total' => (int) ($counts[$status->value] ?? 0),
            ])
            ->values();

        $total = (int) $counts->sum();
        $closing = (int) ($counts[LeadStatus::Closing->value] ?? 0);
        $lost = (int) ($counts[LeadStatus::Lost->value] ?? 0);

        $bySource = Lead::query()
            ->select('source', DB::raw('count(*) as total'))
            ->groupBy('source')
            ->orderByDesc('total')
            ->limit(6)
            ->get();

        return Inertia::render('CRM/Dashboard', [
            'funnel' => $funnel,
            'stats' => [
                'total' => $total,
                'closing' => $closing,
                'lost' => $lost,
                'conversionRate' => $total > 0 ? round($closing / $total * 100, 1) : 0,
                'overdueFollowUp' => Lead::overdueFollowUp()->count(),
            ],
            'bySource' => $bySource,
        ]);
    }

    public function store(StoreLeadRequest $request, LeadService $service): RedirectResponse
    {
        $service->create($request->validated(), $request->user());

        return redirect()->route('crm.leads.index')->with('success', 'Lead berhasil ditambahkan.');
    }

    public function update(UpdateLeadRequest $request, Lead $lead, LeadService $service): RedirectResponse
    {
        $service->update($lead, $request->validated());

        return back()->with('success', 'Lead berhasil diperbarui.');
    }

    public function updateStatus(UpdateLeadStatusRequest $request, Lead $lead, LeadService $service): RedirectResponse
    {
        $service->changeStatus($lead, $request->validated(), $request->user());

        return back()->with('success', 'Status lead diperbarui.');
    }

    /**
     * PRD §4.1/§4.4 — Marketing confirms a deal; this closes the lead's
     * pipeline (CLOSING) and creates the execution Project in one step.
     * See LeadService::confirmDeal().
     */
    public function confirmDeal(ConfirmLeadDealRequest $request, Lead $lead, LeadService $service): RedirectResponse
    {
        $service->confirmDeal($lead, $request->validated(), $request->user());

        return redirect()->route('crm.leads.index')->with('success', 'Deal dikonfirmasi, proyek baru telah dibuat.');
    }
}
