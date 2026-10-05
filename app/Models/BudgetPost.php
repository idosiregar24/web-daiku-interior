<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sprint 12 decision #24 — a freely named budget post of a project
 * ("Alokasi Dana Proyek"); changes only through ProjectBudgetService.
 */
class BudgetPost extends Model
{
    protected $fillable = [
        'project_id',
        'name',
        'sort_order',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Sprint 12 #28 — realisations held for the CEO. */
    public function overrunRequests(): HasMany
    {
        return $this->hasMany(BudgetOverrunRequest::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
