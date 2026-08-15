<?php

namespace App\Services;

use App\Enums\QuotationStatus;
use App\Models\Design;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * RAB builder only this sprint (.claude/plan/sprint-02.md Week 4) — the
 * CEO→PM dual-approval flow (submit() beyond DRAFT/SUBMITTED, approve/
 * reject, SENT_TO_CLIENT/APPROVED/REJECTED) is .claude/plan/sprint-03.md
 * Week 5's job, see QuotationStatus's docblock.
 */
class QuotationService
{
    /**
     * PRD §4.3 "Quotation hanya bisa dibuat jika Design sudah clientAcc =
     * true" — called from DesignService::clientAcc(), never directly from
     * a controller (there's no standalone "create quotation" entry point;
     * it's always a side effect of the Client ACC trigger).
     */
    public function createFromDesign(Design $design, User $actor): Quotation
    {
        if (! $design->client_acc) {
            throw ValidationException::withMessages([
                'design_id' => 'Quotation hanya bisa dibuat dari desain yang sudah di-ACC klien.',
            ]);
        }

        if ($design->lead->quotation()->exists()) {
            throw ValidationException::withMessages([
                'design_id' => 'Lead ini sudah punya quotation.',
            ]);
        }

        return Quotation::create([
            'lead_id' => $design->lead_id,
            'status' => QuotationStatus::Draft->value,
            'created_by' => $actor->id,
        ]);
    }

    /**
     * Replaces the full RAB item list in one go (the builder UI sends the
     * whole current list on every save, not incremental add/remove calls)
     * — only while still DRAFT, matching the RBAC matrix's Estimator-only
     * CRUD and keeping edits impossible once approval has started.
     * `total_price` is always computed server-side from qty × unit_price,
     * never trusted from the client.
     */
    public function replaceItems(Quotation $quotation, array $items): Quotation
    {
        if ($quotation->status !== QuotationStatus::Draft) {
            throw ValidationException::withMessages([
                'items' => 'Item RAB hanya bisa diubah selama quotation berstatus DRAFT.',
            ]);
        }

        return DB::transaction(function () use ($quotation, $items) {
            $quotation->items()->delete();

            $total = 0;

            foreach (array_values($items) as $index => $item) {
                $totalPrice = $item['qty'] * $item['unit_price'];
                $total += $totalPrice;

                $quotation->items()->create([
                    'description' => $item['description'],
                    'qty' => $item['qty'],
                    'unit' => $item['unit'],
                    'unit_price' => $item['unit_price'],
                    'total_price' => $totalPrice,
                    'sort_order' => $index,
                ]);
            }

            $quotation->update(['total_amount' => $total]);

            return $quotation->fresh('items');
        });
    }

    /**
     * Estimator hands the draft off for review. Only reaches SUBMITTED —
     * advancing past that (CEO_REVIEW onward) is Week 5's approval flow.
     */
    public function submit(Quotation $quotation): Quotation
    {
        if ($quotation->status !== QuotationStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => 'Quotation hanya bisa disubmit dari status DRAFT.',
            ]);
        }

        if ($quotation->items()->count() === 0) {
            throw ValidationException::withMessages([
                'items' => 'Tambahkan minimal satu item RAB sebelum submit.',
            ]);
        }

        $quotation->update(['status' => QuotationStatus::Submitted->value]);

        return $quotation->fresh();
    }
}
