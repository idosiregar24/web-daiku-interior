<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Sprint 23 K12d — one payment proof photo attached to "Tandai Klien Sudah
 * Bayar". Created only through App\Services\InvoicePaymentProofService;
 * append-only like the rest of the finance trail (a rejected submission's
 * photos stay as history under their `attempt`). The file sits on the
 * private disk and is served by InvoiceController::paymentProof() — the
 * storage path never leaves the server.
 */
class InvoicePaymentProof extends Model
{
    /** Private disk (storage/app/private) — never `public`. */
    public const DISK = 'local';

    public const MAX_PHOTOS = 3;

    /** Kilobytes, for the `max` validation rule (5 MB). */
    public const MAX_PHOTO_KB = 5120;

    public const PHOTO_MIMES = ['jpg', 'jpeg', 'png', 'webp'];

    protected $fillable = [
        'invoice_id',
        'attempt',
        'path',
        'path_thumb',
        'width',
        'height',
        'size',
        'uploaded_by',
    ];

    protected $hidden = ['path', 'path_thumb'];

    protected $appends = ['url', 'thumb_url'];

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Bukti bayar bersifat append-only dan tidak bisa diubah.'));
        static::deleting(fn () => throw new LogicException('Bukti bayar bersifat append-only dan tidak bisa dihapus.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** The authorised route that streams the full photo (InvoicePolicy::viewPaymentProofs). */
    public function getUrlAttribute(): string
    {
        return route('finance.invoices.proofs.show', ['invoice' => $this->invoice_id, 'proof' => $this->id]);
    }

    public function getThumbUrlAttribute(): string
    {
        return route('finance.invoices.proofs.show', ['invoice' => $this->invoice_id, 'proof' => $this->id, 'thumb' => 1]);
    }
}
