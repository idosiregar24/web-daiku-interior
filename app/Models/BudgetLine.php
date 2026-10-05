<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 12 decision #24 — a RAB item allocated to a budget post, with
 * its figures copied from the item (`sell_price` = the line's budget).
 * One item sits in one post at most (`quotation_item_id` UNIQUE).
 */
class BudgetLine extends Model
{
    protected $fillable = [
        'budget_post_id',
        'quotation_item_id',
        'description',
        'qty',
        'unit_id',
        'unit_price',
        'sell_price',
        'sort_order',
    ];

    protected $with = ['unit:id,code,name'];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'sell_price' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(BudgetPost::class, 'budget_post_id');
    }

    public function quotationItem(): BelongsTo
    {
        return $this->belongsTo(QuotationItem::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
