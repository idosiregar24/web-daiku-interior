<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 14 Sub 01 — a reference link or photo attached to a RAB request
 * (QuotationService::request() / requestAddendum()). Internal: the
 * client's link and PDF never include these.
 */
class QuotationReference extends Model
{
    public const KIND_LINK = 'LINK';

    public const KIND_PHOTO = 'PHOTO';

    /** Private disk — photos are served by QuotationReferenceController, never publicly. */
    public const DISK = 'local';

    public const MAX_LINKS = 5;

    public const MAX_PHOTOS = 8;

    /** Kilobytes, for the `max` validation rule. */
    public const MAX_PHOTO_KB = 5120;

    public const PHOTO_MIMES = ['jpg', 'jpeg', 'png', 'webp'];

    protected $fillable = [
        'quotation_id',
        'kind',
        'url',
        'path',
        'original_name',
        'size',
        'uploaded_by',
    ];

    /** The storage path stays server-side; the UI gets the served URL. */
    protected $hidden = ['path'];

    protected $appends = ['photo_url'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Where the browser loads a photo from (auth-gated route); null for a link. */
    public function getPhotoUrlAttribute(): ?string
    {
        return $this->kind === self::KIND_PHOTO
            ? route('quotations.references.show', ['quotation' => $this->quotation_id, 'reference' => $this->id])
            : null;
    }
}
