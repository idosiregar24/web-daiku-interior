<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Sprint 12 decisions #13–#14 — one public link to one quotation version,
 * created by QuotationService::sendToClient(). Append-only: a sent link is
 * history (who sent which version when), never edited or removed.
 */
class QuotationShareLink extends Model
{
    public const UPDATED_AT = null;

    /** Str::random() length — ~285 bits, not guessable. */
    public const TOKEN_LENGTH = 48;

    protected $fillable = ['quotation_id', 'version', 'token', 'sent_by'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Link penawaran bersifat append-only dan tidak bisa diubah.'));
        static::deleting(fn () => throw new LogicException('Link penawaran bersifat append-only dan tidak bisa dihapus.'));
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function url(): string
    {
        return route('public.quotation.show', $this->token);
    }
}
