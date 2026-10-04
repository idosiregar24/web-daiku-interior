<?php

namespace App\Models;

use App\Enums\DisciplinaryType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * SDM (Sprint 10, §3.1) — a reprimand or warning letter. Append-only like
 * AuditLog: no `updated_at`, and the model refuses update/delete. A wrong
 * entry is cancelled by a PEMBATALAN row pointing here via `voids_id`
 * (see `voidedBy`). Written only by DisciplineService.
 */
class DisciplinaryRecord extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'employee_id',
        'type',
        'issued_on',
        'valid_until',
        'description',
        'link',
        'voids_id',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => DisciplinaryType::class,
            'issued_on' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Catatan kedisiplinan bersifat append-only dan tidak bisa diubah.'));
        static::deleting(fn () => throw new LogicException('Catatan kedisiplinan bersifat append-only dan tidak bisa dihapus.'));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** The record this PEMBATALAN row cancels. */
    public function voids(): BelongsTo
    {
        return $this->belongsTo(self::class, 'voids_id');
    }

    /** The PEMBATALAN row that cancelled this record, if any. */
    public function voidedBy(): HasOne
    {
        return $this->hasOne(self::class, 'voids_id');
    }

    /** Records not cancelled by a PEMBATALAN row (PEMBATALAN rows themselves excluded). */
    public function scopeEffective(Builder $query): Builder
    {
        return $query->where('type', '!=', DisciplinaryType::Pembatalan->value)->whereDoesntHave('voidedBy');
    }

    /** Effective SP1–SP3 still in force on `$date` (default today). */
    public function scopeActiveSp(Builder $query, ?Carbon $date = null): Builder
    {
        $day = ($date ?? now())->toDateString();

        return $query->effective()
            ->whereIn('type', [DisciplinaryType::Sp1->value, DisciplinaryType::Sp2->value, DisciplinaryType::Sp3->value])
            ->whereDate('issued_on', '<=', $day)
            ->whereDate('valid_until', '>=', $day);
    }
}
