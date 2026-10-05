<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Sprint 12 decision #11 — a bagian pekerjaan grouping RAB items. */
class QuotationSection extends Model
{
    protected $fillable = ['quotation_id', 'name', 'sort_order'];

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class, 'section_id')->orderBy('sort_order');
    }
}
