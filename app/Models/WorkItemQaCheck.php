<?php

namespace App\Models;

use App\Enums\WorkItemQaStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Sprint 29 Sub 12 (G1) — the Pengawas's QA decision on one RAB item:
 * LOLOS or PERBAIKAN (+ the finding it opened). Created only through
 * App\Services\WorkItemQaService; append-only — the newest row of an item
 * is its QA status. The photo sits on the private disk and is streamed by
 * WorkItemQaController::photo() — the path never leaves the server.
 */
class WorkItemQaCheck extends Model
{
    /** Private disk (storage/app/private) — never `public`. */
    public const DISK = 'local';

    /** Kilobytes, for the `max` validation rule (8 MB — a phone camera photo). */
    public const MAX_PHOTO_KB = 8192;

    public const PHOTO_MIMES = ['jpg', 'jpeg', 'png', 'webp'];

    protected $fillable = [
        'project_id',
        'project_work_item_id',
        'decision',
        'note',
        'photo_url',
        'photo_path',
        'site_finding_id',
        'checked_by',
    ];

    protected $hidden = ['photo_path'];

    protected function casts(): array
    {
        return [
            'decision' => WorkItemQaStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Pemeriksaan QA item bersifat append-only dan tidak bisa diubah.'));
        static::deleting(fn () => throw new LogicException('Pemeriksaan QA item bersifat append-only dan tidak bisa dihapus.'));
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(ProjectWorkItem::class, 'project_work_item_id');
    }

    public function siteFinding(): BelongsTo
    {
        return $this->belongsTo(SiteFinding::class);
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
