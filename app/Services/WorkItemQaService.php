<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\ProjectStatus;
use App\Enums\SiteFindingStatus;
use App\Enums\WorkItemQaStatus;
use App\Models\Project;
use App\Models\ProjectWorkItem;
use App\Models\SiteFinding;
use App\Models\User;
use App\Models\WorkItemQaCheck;
use App\Support\Quantity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sprint 29 Sub 12 (G1–G3) — "QA per item RAB". A RAB item with a wage that
 * reaches 100 % in the Pengawas's pengecekan waits for QA; the Pengawas
 * (Project::isQaReviewableBy()) decides LOLOS — several at once — or
 * PERBAIKAN, which opens a finding (Sprint 24 D12) that can become a repair
 * task; once that finding closes the item waits for QA again. Decisions are
 * append-only (`work_item_qa_checks`), the newest row of an item counts.
 * The project completes when every item with a wage passed
 * (ProjectService::completeIfFinished()). Projects whose timelines already
 * went through QA keep the per-timeline QA (Project::usesItemQa()).
 */
class WorkItemQaService
{
    public function __construct(
        private WorkItemService $workItemService,
        private InvoicePaymentProofService $photoEncoder,
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
    ) {}

    /**
     * Each item with a wage, its progress and QA status, with its newest check.
     *
     * @return Collection<int, array{item: ProjectWorkItem, progress: float, status: WorkItemQaStatus, latest: ?WorkItemQaCheck, checks: int}>
     */
    public function statuses(Project $project): Collection
    {
        $progress = $this->workItemService->progress($project);
        $checks = WorkItemQaCheck::query()
            ->where('project_id', $project->id)
            ->with(['checker:id,name', 'siteFinding.task:id,title,status,assignee_id,due_date', 'siteFinding.task.assignee:id,name'])
            ->orderBy('id')
            ->get()
            ->groupBy('project_work_item_id');

        return ProjectWorkItem::query()
            ->where('project_id', $project->id)
            ->where('unit_wage', '>', 0)
            ->with(['quotationItem.unit', 'teamLead:id,name'])
            ->get()
            ->sortBy(fn (ProjectWorkItem $item) => [$item->quotationItem->quotation_id, $item->quotationItem->sort_order, $item->quotationItem->id])
            ->values()
            ->map(function (ProjectWorkItem $item) use ($progress, $checks) {
                $rows = $checks->get($item->id, collect());
                $latest = $rows->last();
                $percent = $progress[$item->id] ?? 0.0;

                return [
                    'item' => $item,
                    'progress' => $percent,
                    'status' => $this->statusOf($percent, $latest),
                    'latest' => $latest,
                    'checks' => $rows->count(),
                ];
            });
    }

    /** Every item with a wage passed QA (and there is at least one) — G2. */
    public function allPassed(Project $project): bool
    {
        $statuses = $this->statuses($project);

        return $statuses->isNotEmpty() && $statuses->every(fn (array $row) => $row['status'] === WorkItemQaStatus::Lolos);
    }

    /**
     * The "QC Item" view of Timeline & QC: the items with their QA status,
     * the newest decision (+ its finding / repair task), the counts, and what
     * the viewer may do.
     *
     * @return array<string, mixed>
     */
    public function overview(Project $project, User $viewer): array
    {
        $statuses = $this->statuses($project);
        $active = $project->status === ProjectStatus::Active;

        return [
            'items' => $statuses->map(fn (array $row) => [
                'id' => $row['item']->id,
                'description' => $row['item']->quotationItem->description,
                'volume_label' => trim(Quantity::format($row['item']->quotationItem->qty).' '.($row['item']->quotationItem->unit?->code ?? '')),
                'team_lead' => $row['item']->teamLeadLabel() ?? WorkItemService::NO_TEAM_LEAD,
                'progress' => $row['progress'],
                'status' => $row['status']->value,
                'checks' => $row['checks'],
                'latest' => $row['latest'] ? $this->presentCheck($project, $row['latest']) : null,
            ])->values()->all(),
            'summary' => [
                'total' => $statuses->count(),
                ...collect(WorkItemQaStatus::cases())
                    ->mapWithKeys(fn (WorkItemQaStatus $status) => [strtolower($status->value) => $statuses->where('status', $status)->count()])
                    ->all(),
            ],
            'can' => [
                'decide' => $active && $viewer->can('reviewItemQa', $project),
                // Same abilities as the findings card (SiteFindingsCard).
                'repair' => $active && $viewer->can('manageTargets', $project),
                'resolve' => $active && ($viewer->can('supervise', $project) || $viewer->can('approveExecution', $project)),
            ],
        ];
    }

    /**
     * The Pengawas's decision. LOLOS: one or more items waiting for QA.
     * PERBAIKAN: one item (waiting, or passed before and found wrong after
     * all) — a note is required and a finding is opened. Optional evidence:
     * a link and/or one photo (re-encoded WebP on the private disk).
     *
     * @param  array{decision: string, item_ids: list<int|string>, note?: ?string, photo_url?: ?string}  $data
     */
    public function decide(Project $project, array $data, ?UploadedFile $photo, User $actor): int
    {
        $decision = WorkItemQaStatus::tryFrom($data['decision']);
        $itemIds = collect($data['item_ids'])->map(fn ($id) => (int) $id)->unique()->values();
        $note = filled($data['note'] ?? null) ? trim($data['note']) : null;
        $link = filled($data['photo_url'] ?? null) ? trim($data['photo_url']) : null;

        if (! in_array($decision, [WorkItemQaStatus::Lolos, WorkItemQaStatus::Perbaikan], true)) {
            throw ValidationException::withMessages(['decision' => 'Keputusan QA tidak valid.']);
        }

        if ($decision === WorkItemQaStatus::Perbaikan && ($itemIds->count() !== 1 || $note === null)) {
            throw ValidationException::withMessages([$note === null ? 'note' : 'item_ids' => $note === null
                ? 'Tulis apa yang perlu diperbaiki.'
                : 'Perlu perbaikan diputuskan satu item sekaligus.']);
        }

        // CPU work before the transaction (InvoicePaymentProofService's pipeline).
        $encoded = $photo ? $this->photoEncoder->encode([$photo], 'photo', 'Foto QA')[0]['full'] : null;
        $written = null;

        try {
            $count = DB::transaction(function () use ($project, $decision, $itemIds, $note, $link, $encoded, $actor, &$written) {
                $project = Project::lockForUpdate()->findOrFail($project->id);

                if ($project->status !== ProjectStatus::Active) {
                    throw ValidationException::withMessages(['decision' => 'QA item hanya untuk proyek yang sedang berjalan.']);
                }

                $rows = $this->statuses($project)->keyBy(fn (array $row) => $row['item']->id);

                foreach ($itemIds as $itemId) {
                    $row = $rows->get($itemId);
                    $allowed = $decision === WorkItemQaStatus::Lolos
                        ? [WorkItemQaStatus::Menunggu]
                        : [WorkItemQaStatus::Menunggu, WorkItemQaStatus::Lolos];

                    if (! $row) {
                        throw ValidationException::withMessages(['item_ids' => 'Item ini bukan item berupah proyek ini.']);
                    }

                    if (! in_array($row['status'], $allowed, true)) {
                        throw ValidationException::withMessages(['item_ids' => sprintf(
                            'Item "%s" %s — tidak bisa diputuskan %s.',
                            $row['item']->quotationItem->description,
                            $row['status'] === WorkItemQaStatus::Belum ? 'belum mencapai 100 %' : 'berstatus '.$row['status']->label(),
                            $decision->label(),
                        )]);
                    }
                }

                if ($encoded !== null) {
                    $written = "work-item-qa/{$project->id}/".Str::random(40).'.webp';

                    if (! Storage::disk(WorkItemQaCheck::DISK)->put($written, $encoded)) {
                        throw new \RuntimeException('Foto QA gagal disimpan.');
                    }
                }

                $finding = null;

                if ($decision === WorkItemQaStatus::Perbaikan) {
                    $item = $rows->get($itemIds->first())['item'];
                    $finding = SiteFinding::create([
                        'project_id' => $project->id,
                        'milestone_id' => null,
                        'description' => 'QA item "'.$item->quotationItem->description.'": '.$note,
                        'photo_url' => $link,
                        'status' => SiteFindingStatus::Terbuka->value,
                        'reported_by' => $actor->id,
                    ]);
                }

                foreach ($itemIds as $itemId) {
                    WorkItemQaCheck::create([
                        'project_id' => $project->id,
                        'project_work_item_id' => $itemId,
                        'decision' => $decision->value,
                        'note' => $note,
                        'photo_url' => $link,
                        'photo_path' => $written,
                        'site_finding_id' => $finding?->id,
                        'checked_by' => $actor->id,
                    ]);
                }

                $descriptions = $itemIds->map(fn (int $id) => $rows->get($id)['item']->quotationItem->description);
                $this->auditLogService->record(
                    $decision === WorkItemQaStatus::Lolos ? 'work_item_qa.passed' : 'work_item_qa.repair',
                    $project,
                    null,
                    ['items' => $itemIds->all(), 'note' => $note, 'site_finding_id' => $finding?->id],
                    $actor,
                );
                $this->notifyPm($project, $actor, $decision === WorkItemQaStatus::Lolos
                    ? "{$actor->name} meloloskan QA {$itemIds->count()} item di proyek \"{$project->name}\": ".$descriptions->take(3)->implode(', ').($itemIds->count() > 3 ? ', …' : '').'.'
                    : "{$actor->name}: item \"{$descriptions->first()}\" di proyek \"{$project->name}\" perlu perbaikan — {$note}");

                if ($decision === WorkItemQaStatus::Lolos) {
                    app(ProjectService::class)->completeIfFinished($project, notifyWhenBlocked: true);
                }

                return $itemIds->count();
            });
        } catch (Throwable $e) {
            if ($written !== null) {
                Storage::disk(WorkItemQaCheck::DISK)->delete($written);
            }

            throw $e;
        }

        return $count;
    }

    private function statusOf(float $progress, ?WorkItemQaCheck $latest): WorkItemQaStatus
    {
        return match (true) {
            $progress < 100 => WorkItemQaStatus::Belum,
            $latest === null => WorkItemQaStatus::Menunggu,
            $latest->decision === WorkItemQaStatus::Lolos => WorkItemQaStatus::Lolos,
            // Repaired (its finding closed) → QA again.
            $latest->siteFinding?->status === SiteFindingStatus::Terbuka => WorkItemQaStatus::Perbaikan,
            default => WorkItemQaStatus::Menunggu,
        };
    }

    /** @return array<string, mixed> */
    private function presentCheck(Project $project, WorkItemQaCheck $check): array
    {
        $finding = $check->siteFinding;

        return [
            'id' => $check->id,
            'decision' => $check->decision->value,
            'note' => $check->note,
            'photo_url' => $check->photo_url,
            'photo_href' => $check->photo_path ? route('projects.item-qa.photo', ['project' => $project->id, 'check' => $check->id]) : null,
            'checker' => $check->checker?->name,
            'at' => $check->created_at?->toIso8601String(),
            'finding' => $finding ? [
                'id' => $finding->id,
                'description' => $finding->description,
                'status' => $finding->status->value,
                'task' => $finding->task ? [
                    'title' => $finding->task->title,
                    'status' => $finding->task->status->value,
                    'assignee' => $finding->task->assignee?->name,
                    'due_date' => $finding->task->due_date?->toDateString(),
                ] : null,
            ] : null,
        ];
    }

    private function notifyPm(Project $project, User $actor, string $message): void
    {
        $this->notificationService->notifyMany(
            collect([$project->pm, $project->assistantPm])->filter(fn (?User $user) => $user && $user->id !== $actor->id),
            NotificationType::WorkItemQaDecided,
            'QA Item RAB',
            $message,
            ['project_id' => $project->id],
        );
    }
}
