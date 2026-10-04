<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_id',
        'description',
        'qty',
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
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
