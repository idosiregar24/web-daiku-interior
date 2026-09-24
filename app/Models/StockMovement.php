<?php

namespace App\Models;

use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only stock ledger (see the stock_movements migration's
 * docblock). Written only by StockService; there is no update or delete
 * path anywhere.
 */
class StockMovement extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'material_id',
        'project_id',
        'type',
        'qty',
        'stock_after',
        'movement_date',
        'note',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'qty' => 'integer',
            'stock_after' => 'integer',
            'movement_date' => 'date',
        ];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeByType(Builder $query, ?string $type): Builder
    {
        return $query->when($type, fn (Builder $q) => $q->where('type', $type));
    }
}
