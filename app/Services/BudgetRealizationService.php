<?php

namespace App\Services;

use App\Models\BudgetLine;
use App\Models\BudgetOverrunRequest;
use App\Models\BudgetPost;
use App\Models\BudgetRealization;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 12 decisions #27–#28 — the PM records what each allocated item
 * really cost (real qty × harga modal, optional vendor), so Finance sees
 * budget vs realisation per post. A realisation that would push its post
 * over the post's budget is refused; the PM can send it to the CEO, who
 * approves (it is then recorded as typed) or rejects it. One waiting
 * request per post. Realisations are append-only (a correction cancels
 * an earlier row). Money is computed in whole cents.
 *
 * Who may: ProjectPolicy::manageBudget() (record / correct / request) and
 * the CEO (decide) — see routes/web.php.
 */
class BudgetRealizationService
{
    public function __construct(
        private ProjectBudgetService $budgetService,
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array{qty_actual: numeric, unit_cost: numeric, vendor_id?: ?int, note?: ?string}  $data
     */
    public function record(BudgetLine $line, array $data, User $actor): BudgetRealization
    {
        return DB::transaction(function () use ($line, $data, $actor) {
            $post = $this->lockedPost($line);
            $totalCents = $this->totalCents($data);
            $overCents = $this->realizedCents($post) + $totalCents - $this->budgetCents($post);

            if ($overCents > 0) {
                throw ValidationException::withMessages([
                    'overrun' => "Melebihi anggaran pos {$post->name} sebesar {$this->rupiah($overCents)} — ajukan persetujuan CEO.",
                ]);
            }

            return $this->store($line, $data, $actor);
        });
    }

    /** Append-only correction: a new row with negative amounts cancelling `$realization`. */
    public function reverse(BudgetRealization $realization, ?string $note, User $actor): BudgetRealization
    {
        return DB::transaction(function () use ($realization, $note, $actor) {
            $this->lockedPost($realization->line);
            $realization = BudgetRealization::lockForUpdate()->findOrFail($realization->id);

            if ($realization->reverses_id !== null) {
                throw ValidationException::withMessages(['realization' => 'Baris pembatalan tidak bisa dibatalkan lagi.']);
            }

            if (BudgetRealization::where('reverses_id', $realization->id)->exists()) {
                throw ValidationException::withMessages(['realization' => 'Realisasi ini sudah dibatalkan.']);
            }

            return BudgetRealization::create([
                'budget_line_id' => $realization->budget_line_id,
                'qty_actual' => -(float) $realization->qty_actual,
                'unit_cost' => $realization->unit_cost,
                'total_cost' => -(float) $realization->total_cost,
                'vendor_id' => $realization->vendor_id,
                'note' => filled($note) ? trim($note) : 'Koreksi / pembatalan',
                'reverses_id' => $realization->id,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);
        });
    }

    /**
     * Decision #28 — the refused realisation goes to the CEO with a reason.
     *
     * @param  array{qty_actual: numeric, unit_cost: numeric, vendor_id?: ?int, note?: ?string, reason: string}  $data
     */
    public function requestOverrun(BudgetLine $line, array $data, User $actor): BudgetOverrunRequest
    {
        return DB::transaction(function () use ($line, $data, $actor) {
            $post = $this->lockedPost($line);
            $overCents = $this->realizedCents($post) + $this->totalCents($data) - $this->budgetCents($post);

            if ($overCents <= 0) {
                throw ValidationException::withMessages(['reason' => 'Realisasi ini tidak melebihi anggaran pos — simpan langsung.']);
            }

            if ($post->overrunRequests()->waiting()->exists()) {
                throw ValidationException::withMessages(['reason' => "Masih ada pengajuan overrun pos {$post->name} yang menunggu keputusan CEO."]);
            }

            $request = $post->overrunRequests()->create([
                'budget_line_id' => $line->id,
                'payload' => [
                    'qty_actual' => round((float) $data['qty_actual'], 2),
                    'unit_cost' => round((float) $data['unit_cost'], 2),
                    'vendor_id' => $data['vendor_id'] ?? null,
                    'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                ],
                'amount_over' => $overCents / 100,
                'reason' => trim($data['reason']),
                'requested_by' => $actor->id,
                'status' => BudgetOverrunRequest::STATUS_WAITING,
            ]);

            $this->auditLogService->record('finance.budget_overrun_requested', $request, null, [
                'project_id' => $post->project_id,
                'post' => $post->name,
                'item' => $line->description,
                'amount_over' => $overCents / 100,
                'reason' => $request->reason,
            ], $actor);

            $this->notificationService->notifyRoles(
                ['CEO'],
                'budget_overrun_requested',
                'Persetujuan Overrun Anggaran',
                "{$actor->name} minta persetujuan realisasi \"{$line->description}\" di pos {$post->name} ({$post->project->name}) — melebihi anggaran {$this->rupiah($overCents)}.",
                ['project_id' => $post->project_id, 'overrun_request_id' => $request->id],
            );

            return $request;
        });
    }

    /** Decision #28 — the CEO approves (the held realisation is recorded) or rejects (with a note). */
    public function decide(BudgetOverrunRequest $request, bool $approve, ?string $note, User $actor): BudgetOverrunRequest
    {
        return DB::transaction(function () use ($request, $approve, $note, $actor) {
            $post = $this->lockedPost($request->line, checkWritable: $approve);
            $request = BudgetOverrunRequest::lockForUpdate()->findOrFail($request->id);

            if ($request->status !== BudgetOverrunRequest::STATUS_WAITING) {
                throw ValidationException::withMessages(['decision' => 'Pengajuan ini sudah diputuskan.']);
            }

            $request->update([
                'status' => $approve ? BudgetOverrunRequest::STATUS_APPROVED : BudgetOverrunRequest::STATUS_REJECTED,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => filled($note) ? trim($note) : null,
            ]);

            if ($approve) {
                $this->store($request->line, $request->payload, $actor, $request);
            }

            $this->auditLogService->record($approve ? 'finance.budget_overrun_approved' : 'finance.budget_overrun_rejected', $request, [
                'status' => BudgetOverrunRequest::STATUS_WAITING,
            ], [
                'status' => $request->status,
                'project_id' => $post->project_id,
                'post' => $post->name,
                'amount_over' => (float) $request->amount_over,
                'note' => $request->decision_note,
            ], $actor);

            $this->notificationService->notifyMany(
                [$request->requester],
                $approve ? 'budget_overrun_approved' : 'budget_overrun_rejected',
                $approve ? 'Overrun Anggaran Disetujui' : 'Overrun Anggaran Ditolak',
                $approve
                    ? "CEO menyetujui realisasi \"{$request->line->description}\" di pos {$post->name} — sudah tercatat."
                    : "CEO menolak realisasi \"{$request->line->description}\" di pos {$post->name}: {$request->decision_note}",
                ['project_id' => $post->project_id, 'overrun_request_id' => $request->id],
            );

            return $request;
        });
    }

    /** @param  array{qty_actual: numeric, unit_cost: numeric, vendor_id?: ?int, note?: ?string}  $data */
    private function store(BudgetLine $line, array $data, User $actor, ?BudgetOverrunRequest $request = null): BudgetRealization
    {
        return $line->realizations()->create([
            'qty_actual' => round((float) $data['qty_actual'], 2),
            'unit_cost' => round((float) $data['unit_cost'], 2),
            'total_cost' => $this->totalCents($data) / 100,
            'vendor_id' => $data['vendor_id'] ?? null,
            'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
            'overrun_request_id' => $request?->id,
            // The PM who typed it, also when the CEO's approval stores it.
            'recorded_by' => $request?->requested_by ?? $actor->id,
            'recorded_at' => now(),
        ]);
    }

    /** Row-locks the line's post (serialises its realisations) after the project checks. */
    private function lockedPost(BudgetLine $line, bool $checkWritable = true): BudgetPost
    {
        $post = BudgetPost::lockForUpdate()->findOrFail($line->budget_post_id);

        if ($checkWritable) {
            $this->budgetService->ensureWritable($post->project);
        }

        return $post;
    }

    private function budgetCents(BudgetPost $post): int
    {
        return (int) round((float) $post->lines()->sum('sell_price') * 100);
    }

    private function realizedCents(BudgetPost $post): int
    {
        return (int) round((float) BudgetRealization::whereIn('budget_line_id', $post->lines()->select('id'))->sum('total_cost') * 100);
    }

    /** @param  array{qty_actual: numeric, unit_cost: numeric}  $data */
    private function totalCents(array $data): int
    {
        return (int) round(Quantity::toHundredths($data['qty_actual']) * (int) round((float) $data['unit_cost'] * 100) / 100);
    }

    private function rupiah(int $cents): string
    {
        return 'Rp '.number_format($cents / 100, 0, ',', '.');
    }
}
