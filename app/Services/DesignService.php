<?php

namespace App\Services;

use App\Enums\DesignStatus;
use App\Enums\LeadStatus;
use App\Models\Design;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DesignService
{
    public function __construct(private QuotationService $quotationService) {}

    /**
     * PRD §4.1 "Konversi ke Desain: Ketika status DEAL_DESAIN, sistem
     * membuka modul Desain untuk lead tersebut" — a Design record can
     * only be opened for a lead that has actually reached DEAL_DESAIN,
     * and only once (`leads.id` is unique on `designs`).
     */
    public function create(Lead $lead, array $data): Design
    {
        if ($lead->status !== LeadStatus::DealDesain) {
            throw ValidationException::withMessages([
                'lead_id' => 'Modul Desain hanya bisa dibuka untuk lead berstatus DEAL_DESAIN.',
            ]);
        }

        if ($lead->design()->exists()) {
            throw ValidationException::withMessages([
                'lead_id' => 'Lead ini sudah punya proyek desain.',
            ]);
        }

        return Design::create([
            ...$data,
            'lead_id' => $lead->id,
            'deadline' => $this->calculateDeadline($data['start_date'] ?? null, $data['target_hari'] ?? null),
        ]);
    }

    /**
     * Brief fields + status — no separate changeStatus() like LeadService:
     * PRD doesn't describe a validated transition graph for the 13
     * DesignStatus values the way it does for LeadStatus/PipelineLog, so
     * (like MilestoneService::update()) this is a plain field update.
     * `client_acc`/`acc_date` are excluded — those only ever change
     * through `clientAcc()` below, which also creates the Quotation.
     */
    public function update(Design $design, array $data): Design
    {
        unset($data['client_acc'], $data['acc_date']);

        $startDate = $data['start_date'] ?? $design->start_date?->toDateString();
        $targetHari = $data['target_hari'] ?? $design->target_hari;

        $design->update([
            ...$data,
            'deadline' => $this->calculateDeadline($startDate, $targetHari),
        ]);

        return $design;
    }

    /**
     * PRD §4.2 "Client ACC: Konfirmasi ACC desain → trigger ke tahap
     * Gambar RAB → Penawaran" + §4.3 "Quotation hanya bisa dibuat jika
     * Design sudah clientAcc = true". Only actionable once the design has
     * actually reached WAITING_ACC_DESAIN (client has something to
     * approve) — moves straight to GAMBAR_RAB since that's exactly the
     * stage the newly-created Quotation represents, no separate manual
     * step needed to leave ACC_DESAIN.
     */
    public function clientAcc(Design $design, User $actor): Design
    {
        if ($design->client_acc) {
            throw ValidationException::withMessages([
                'client_acc' => 'Desain ini sudah di-ACC klien.',
            ]);
        }

        if ($design->status !== DesignStatus::WaitingAccDesain) {
            throw ValidationException::withMessages([
                'client_acc' => 'Client ACC hanya bisa dikonfirmasi saat status WAITING_ACC_DESAIN.',
            ]);
        }

        return DB::transaction(function () use ($design, $actor) {
            $design->update([
                'client_acc' => true,
                'acc_date' => now()->toDateString(),
                'status' => DesignStatus::GambarRab->value,
            ]);

            $this->quotationService->createFromDesign($design->fresh(), $actor);

            return $design->fresh();
        });
    }

    private function calculateDeadline(?string $startDate, ?int $targetHari): ?string
    {
        if (! $startDate || ! $targetHari) {
            return null;
        }

        return Carbon::parse($startDate)->addDays($targetHari)->toDateString();
    }
}
