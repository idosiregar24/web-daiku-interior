<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRD §4.8 "Kebutuhan Material Proyek". `qty_used` is not fillable — it
 * only grows through StockService::stockOut().
 */
class ProjectMaterial extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'material_id',
        'qty_planned',
    ];

    protected function casts(): array
    {
        return [
            'qty_planned' => 'integer',
            'qty_used' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
