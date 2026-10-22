<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 29 Sub 09 — the draft of a project's next pengecekan (one per
 * project): % per item, notes and links, filled by the Pengawas or the
 * Admin Proyek; removed once the Pengawas sends it.
 */
class WorkItemProgressDraft extends Model
{
    protected $fillable = [
        'project_id',
        'entries',
        'notes',
        'links',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'entries' => 'array',
            'links' => 'array',
        ];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
