<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRD §4.4 "Progress Log" — append-only (no `updated_at`, see the
 * migration), PM-written chronological log per project. Since Sprint 24
 * D11 it is a note ("Catatan Progress"): the progress itself is
 * Project::actualProgress(); `percentage` only survives on older rows.
 */
class ProgressLog extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'project_id',
        'logged_by',
        'description',
        'ref_urls',
        'log_date',
    ];

    protected function casts(): array
    {
        return [
            'percentage' => 'integer',
            'ref_urls' => 'array',
            'log_date' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function logger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by');
    }
}
