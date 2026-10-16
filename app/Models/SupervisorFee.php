<?php

namespace App\Models;

use App\Enums\SupervisorPaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sprint 24 D4 — "Jasa Pengawas" of a project: the total the PM agreed at
 * the TM and the DP percentage (default 10 %, editable). Payments against
 * it never exceed the total (SupervisorPaymentService).
 */
class SupervisorFee extends Model
{
    public const DEFAULT_DP_PERCENT = 10;

    protected $fillable = [
        'project_id',
        'supervisor_id',
        'total_amount',
        'dp_percent',
        'set_by',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'dp_percent' => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupervisorPayment::class)->orderBy('id');
    }

    /** DP % × total, rounded to the rupiah. */
    public function dpAmount(): float
    {
        return round((float) $this->total_amount * (float) $this->dp_percent / 100);
    }

    /** Everything asked, approved or paid (rejected requests don't count). */
    public function committedAmount(?int $exceptPaymentId = null): float
    {
        return (float) $this->payments()
            ->whereIn('status', SupervisorPaymentStatus::committedValues())
            ->when($exceptPaymentId, fn ($query) => $query->whereKeyNot($exceptPaymentId))
            ->sum('amount');
    }

    public function paidAmount(): float
    {
        return (float) $this->payments()->where('status', SupervisorPaymentStatus::Dibayar->value)->sum('amount');
    }

    public function remainingAmount(): float
    {
        return max(0, round((float) $this->total_amount - $this->committedAmount(), 2));
    }
}
