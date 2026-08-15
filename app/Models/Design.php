<?php

namespace App\Models;

use App\Enums\DesignStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Design extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'pic_id',
        'jenis_project',
        'status',
        'target_hari',
        'start_date',
        'deadline',
        'delay_hari',
        'design_urls',
        'brief_note',
        'problem',
        'client_acc',
        'acc_date',
    ];

    protected function casts(): array
    {
        return [
            'status' => DesignStatus::class,
            'start_date' => 'date',
            'deadline' => 'date',
            'acc_date' => 'date',
            'design_urls' => 'array',
            'client_acc' => 'boolean',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** PIC utama — PRD §4.2. Named `pic`, not `pic_id`'s naive camel equivalent, no collision either way. */
    public function pic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_id');
    }

    /** Sub-staff (PRD §4.2) — pivot table `design_staff` carries `role_note` (e.g. "3D modeling"). */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'design_staff')
            ->withPivot('role_note')
            ->withTimestamps();
    }

    public function scopeByStatus(Builder $query, ?string $status): Builder
    {
        return $query->when($status, fn (Builder $q) => $q->where('status', $status));
    }
}
