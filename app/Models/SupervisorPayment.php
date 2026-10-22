<?php

namespace App\Models;

use App\Enums\SupervisorPaymentKind;
use App\Enums\SupervisorPaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 24 D4 — one Jasa Pengawas payment (DP / MINGGUAN / PELUNASAN).
 * Never deleted (golden rule #7): a rejected request stays as DITOLAK.
 */
class SupervisorPayment extends Model
{
    protected $fillable = [
        'project_id',
        'supervisor_fee_id',
        'supervisor_id',
        'kind',
        'amount',
        'breakdown',
        'status',
        'weekly_report_id',
        'requested_by',
        'requested_at',
        'decided_by',
        'decided_at',
        'decision_note',
        'paid_by',
        'paid_at',
        'bank_account_id',
        'finance_transaction_id',
    ];

    protected $appends = ['label'];

    protected function casts(): array
    {
        return [
            'kind' => SupervisorPaymentKind::class,
            'status' => SupervisorPaymentStatus::class,
            'amount' => 'decimal:2',
            'breakdown' => 'array',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function fee(): BelongsTo
    {
        return $this->belongsTo(SupervisorFee::class, 'supervisor_fee_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function weeklyReport(): BelongsTo
    {
        return $this->belongsTo(WeeklyReport::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /**
     * "DP · Proyek Kopi Oz", "Mingguan minggu ke-3 · Proyek Kopi Oz" — the
     * one sentence for Finance's queue, its notifications and the dialog
     * (same idea as Invoice::confirmationLabel(), Sprint 23 K12b).
     */
    public function getLabelAttribute(): string
    {
        $kind = $this->kind === SupervisorPaymentKind::Mingguan && $this->weeklyReport
            ? 'Mingguan '.lcfirst($this->weeklyReport->label())
            : $this->kind?->label();
        $project = $this->project?->name;

        // Project names usually start with "Proyek" already ("Proyek Kopi Oz").
        $project = $project && ! str_starts_with(mb_strtolower($project), 'proyek ') ? "Proyek {$project}" : $project;

        return $project ? "{$kind} · {$project}" : (string) $kind;
    }
}
