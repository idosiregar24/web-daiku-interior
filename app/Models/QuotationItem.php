<?php

namespace App\Models;

use App\Support\Quantity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationItem extends Model
{
    use HasFactory;

    /** Sprint 23 K5 — volume taken from P (satuan m/lari only). */
    public const QTY_SOURCE_P = 'P';

    /** Sprint 23 K5 — volume taken from T/L (satuan m/lari only). */
    public const QTY_SOURCE_TL = 'TL';

    /** Sprint 23 K5 — volume = P × T/L (every unit other than m/lari). */
    public const QTY_SOURCE_P_X_TL = 'PxTL';

    public const QTY_SOURCES = [self::QTY_SOURCE_P, self::QTY_SOURCE_TL, self::QTY_SOURCE_P_X_TL];

    protected $fillable = [
        'quotation_id',
        'section_id',
        'description',
        'dim_length',
        'dim_width_height',
        'qty',
        // Sprint 23 K5 — null = typed by the Estimator; P / TL / PxTL = from the dimensions.
        'qty_source',
        'unit_id',
        'unit_price',
        'total_price',
        'sort_order',
    ];

    /** The RAB table, PDF and revision snapshot all print the unit (Master Satuan, Sprint 11). */
    protected $with = ['unit:id,code,name'];

    protected function casts(): array
    {
        return [
            // Fractional quantities allowed (Sprint 11 decision #3) — 2,5 m².
            'qty' => 'float',
            // Sprint 12 #11 — Dimensi P × T/L; Sprint 23 K5 may derive the volume from them (qty_source).
            'dim_length' => 'float',
            'dim_width_height' => 'float',
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
        ];
    }

    /**
     * Sprint 23 K5 — the volume P / T/L give for `$source` in hundredths
     * (DECIMAL(12,2), App\Support\Quantity), or null when the dimension(s)
     * it needs are missing. P × T/L is rounded to the hundredth.
     */
    public static function derivedQtyHundredths(?string $source, float|int|string|null $length, float|int|string|null $widthHeight): ?int
    {
        $p = $length === null || $length === '' ? null : Quantity::toHundredths($length);
        $tl = $widthHeight === null || $widthHeight === '' ? null : Quantity::toHundredths($widthHeight);

        return match ($source) {
            self::QTY_SOURCE_P => $p,
            self::QTY_SOURCE_TL => $tl,
            self::QTY_SOURCE_P_X_TL => $p === null || $tl === null ? null : (int) round($p * $tl / 100),
            default => null,
        };
    }

    /**
     * Sprint 23 K7 — what makes two lines of different versions "the same
     * item": section, name, P, T/L, volume, unit and unit price (text
     * trimmed, case and inner spaces ignored; numbers in hundredths /
     * cents). Items are rewritten on every save, so a ✔ is carried by
     * this, never by id.
     */
    public static function fingerprintOf(?string $sectionName, string $description, float|int|string|null $length, float|int|string|null $widthHeight, float|int|string|null $qty, ?int $unitId, float|int|string|null $unitPrice): string
    {
        $text = fn (?string $value) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $value)));
        $number = fn ($value) => $value === null || $value === '' ? '' : (string) Quantity::toHundredths($value);

        return hash('sha256', implode('|', [
            // A line without a section prints (and is re-saved by the builder) as "Umum".
            $text($sectionName ?? 'Umum'),
            $text($description),
            $number($length),
            $number($widthHeight),
            $number($qty),
            (string) $unitId,
            $number($unitPrice),
        ]));
    }

    /** This line's fingerprintOf() — expects `section` loaded (or none). */
    public function fingerprint(): string
    {
        return self::fingerprintOf($this->section?->name, $this->description, $this->dim_length, $this->dim_width_height, $this->qty, $this->unit_id, $this->unit_price);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(QuotationSection::class, 'section_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
