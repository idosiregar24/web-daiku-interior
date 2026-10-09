<?php

namespace App\Models;

use App\Services\PortfolioPhotoService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Sprint 20 Sub 04 — a portfolio photo, stored by PortfolioPhotoService as
 * two WebP files on the `public` disk: `path` (≤ 2000 px) and `path_thumb`
 * (600 px wide). EXIF (GPS of the client's house) is gone after re-encoding.
 */
class PortfolioPhoto extends Model
{
    public const DISK = 'public';

    protected $fillable = [
        'portfolio_item_id',
        'path',
        'path_thumb',
        'width',
        'height',
        'alt',
        'caption',
        'sort_order',
    ];

    protected $hidden = ['path', 'path_thumb'];

    protected $appends = ['url', 'thumb_url'];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PortfolioItem::class, 'portfolio_item_id');
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk(self::DISK)->url($this->path);
    }

    public function getThumbUrlAttribute(): string
    {
        return Storage::disk(self::DISK)->url($this->path_thumb);
    }

    /** Width of the thumbnail file, for `srcset` (600 unless the original was narrower). */
    public function thumbWidth(): int
    {
        return min(PortfolioPhotoService::THUMB_WIDTH, $this->width);
    }
}
