<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sprint 32 — a position in a Tukang team with its default weight (File D
 * "Master Persentasi": Ketua Tim 24 … Perusahaan 5). `is_company` = the
 * company's share. Rows come from the migration; the CEO edits names/weights
 * (WageSplitService::saveLevels()).
 */
class CareerLevel extends Model
{
    protected $fillable = ['name', 'weight', 'is_company', 'sort_order'];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'is_company' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
