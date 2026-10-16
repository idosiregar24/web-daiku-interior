<?php

namespace App\Models;

use App\Enums\WorkingDrawingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Sprint 24 D2 — the project's "Gambar Kerja": born with the project
 * (WorkingDrawingService::openForProject()), assigned by a Kepala Desain,
 * final at "Gambar Kerja Selesai" (no client ACC, no invoice). One per
 * project; moves the project from GAMBAR_KERJA to TM.
 */
class WorkingDrawing extends Model
{
    protected $fillable = [
        'project_id',
        'status',
        'pic_id',
        'assigned_by',
        'assigned_at',
        'start_date',
        'target_hari',
        'deadline',
        'drawing_urls',
        'note',
        'links_updated_by',
        'links_updated_at',
        'completed_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => WorkingDrawingStatus::class,
            'assigned_at' => 'datetime',
            'start_date' => 'date',
            'target_hari' => 'integer',
            'deadline' => 'date',
            'drawing_urls' => 'array',
            'links_updated_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function pic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function linksUpdater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'links_updated_by');
    }

    /** The assistant architects (the PIC is never also listed here). */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'working_drawing_staff')->withTimestamps();
    }

    /** The PIC or an assistant. */
    public function hasMember(User $user): bool
    {
        if ((int) $this->pic_id === (int) $user->id) {
            return true;
        }

        return $this->relationLoaded('staff')
            ? $this->staff->contains('id', $user->id)
            : $this->staff()->whereKey($user->id)->exists();
    }

    /** Same rule as Design::visibleTo(): a plain architect lists only their own drawings. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->hasRole('DESIGNER') || $user->hasAnyRole(['KEPALA_DESAIN', 'SUPERADMIN', 'CEO'])) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('pic_id', $user->id)
            ->orWhereHas('staff', fn (Builder $staff) => $staff->whereKey($user->id)));
    }

    /** Where this user is PIC or assistant — their own work, even for a Kepala Desain. */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('pic_id', $user->id)
            ->orWhereHas('staff', fn (Builder $staff) => $staff->whereKey($user->id)));
    }
}
