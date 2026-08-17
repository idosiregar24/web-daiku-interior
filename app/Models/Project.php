<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'name',
        'pm_id',
        'status',
        'start_date',
        'end_date',
        'contract_value',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'contract_value' => 'decimal:2',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** Named `pm`, not `projectManager` — matches the `pm_id` column, no snake_case collision either way. */
    public function pm(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pm_id');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('order');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function progressLogs(): HasMany
    {
        return $this->hasMany(ProgressLog::class)->latest('log_date');
    }

    public function termins(): HasMany
    {
        return $this->hasMany(Termin::class)->orderBy('termin_number');
    }

    public function scopeByStatus(Builder $query, ?string $status): Builder
    {
        return $query->when($status, fn (Builder $q) => $q->where('status', $status));
    }

    public function scopeByPm(Builder $query, ?int $pmId): Builder
    {
        return $query->when($pmId, fn (Builder $q) => $q->where('pm_id', $pmId));
    }
}
