<?php

namespace App\Models;

use App\Enums\TerminStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** PRD §4.4/§4.7/§6.4 "Termin Schedule" — jadwal Sabtu, lihat TerminService. */
class Termin extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'milestone_id',
        'termin_number',
        'percentage',
        'amount',
        'scheduled_date',
        'status',
        'bank_account_id',
        'invoice_url',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TerminStatus::class,
            'amount' => 'decimal:2',
            'scheduled_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function scopeByStatus(Builder $query, ?string $status): Builder
    {
        return $query->when($status, fn (Builder $q) => $q->where('status', $status));
    }
}
