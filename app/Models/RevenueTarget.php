<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** PRD §4.10 "Revenue vs Target" — monthly target set by the CEO (`month` = 'YYYY-MM'). */
class RevenueTarget extends Model
{
    use HasFactory;

    protected $fillable = [
        'month',
        'target_amount',
        'set_by',
    ];

    protected function casts(): array
    {
        return [
            'target_amount' => 'decimal:2',
        ];
    }

    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
