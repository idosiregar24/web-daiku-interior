<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineLog extends Model
{
    /** Append-only log — no `updated_at`, see database-standards.md §3. */
    const UPDATED_AT = null;

    protected $fillable = [
        'lead_id',
        'from_status',
        'to_status',
        'changed_by',
        'note',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
