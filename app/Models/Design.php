<?php

namespace App\Models;

use App\Enums\DesignStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Design extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'quotation_id',
        'pic_id',
        'assigned_by',
        'assigned_at',
        'jenis_project',
        'status',
        'target_hari',
        'start_date',
        'deadline',
        'delay_hari',
        'delay_counted_on',
        'design_urls',
        'brief_note',
        'problem',
        'client_acc',
        'acc_date',
        'revision_count',
        'sent_to_client_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DesignStatus::class,
            'start_date' => 'date',
            'deadline' => 'date',
            'delay_hari' => 'integer',
            'delay_counted_on' => 'date',
            'acc_date' => 'date',
            'design_urls' => 'array',
            'client_acc' => 'boolean',
            'assigned_at' => 'datetime',
            'revision_count' => 'integer',
            'sent_to_client_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** Sprint 12 #16 — the RAB Jasa Desain this design was paid through (null = a pre-Sprint-12 design). */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /** PIC utama — PRD §4.2. Named `pic`, not `pic_id`'s naive camel equivalent, no collision either way. */
    public function pic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_id');
    }

    /** Sprint 12 #15 — the Kepala Desain who assigned the team. */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * Sub-staff (PRD §4.2) — pivot table `design_staff` carries `role_note`
     * (e.g. "3D modeling"). Sprint 12 #15's "asisten arsitek".
     */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'design_staff')
            ->withPivot('role_note')
            ->withTimestamps();
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(DesignRevision::class);
    }

    public function discussions(): HasMany
    {
        return $this->hasMany(DesignDiscussion::class);
    }

    /**
     * Sprint 12 — born from a RAB Jasa Desain: its status moves only
     * through DesignService's actions (pay → assign → send → revise /
     * approve), never the brief form's status picker.
     */
    public function isFlowManaged(): bool
    {
        return $this->quotation_id !== null;
    }

    /** Decision #15 — the PIC or an assistant ("arsitek melihat desain yang ia anggotai"). */
    public function hasMember(User $user): bool
    {
        if ((int) $this->pic_id === (int) $user->id) {
            return true;
        }

        return $this->relationLoaded('staff')
            ? $this->staff->contains('id', $user->id)
            : $this->staff()->whereKey($user->id)->exists();
    }

    /**
     * The design's own work is over: a Sprint 12 design once the client
     * approved it (the rest happens on the quotation / project), an older
     * one at DONE_PRODUKSI.
     */
    public function isDone(): bool
    {
        return $this->isFlowManaged()
            ? $this->client_acc
            : $this->status === DesignStatus::DoneProduksi;
    }

    public function scopeByStatus(Builder $query, ?string $status): Builder
    {
        return $query->when($status, fn (Builder $q) => $q->where('status', $status));
    }

    /** Started work — not still waiting for payment / assignment (decision #16). */
    public function scopeStarted(Builder $query): Builder
    {
        return $query->whereNotIn('status', DesignStatus::lockedValues());
    }

    /** What a user may list: a plain architect only their own designs, everyone else all. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->hasRole('DESIGNER') || $user->hasAnyRole(['KEPALA_DESAIN', 'SUPERADMIN'])) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('pic_id', $user->id)
            ->orWhereHas('staff', fn (Builder $staff) => $staff->whereKey($user->id)));
    }
}
