<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sprint 11 §5.5 Lapis 2 — "plywood" means "triplek". Used by
 * MaterialCatalogService when it builds match keys; both columns are
 * stored normalized. Data Master → Sinonim Barang (SUPERADMIN).
 */
class MaterialSynonym extends Model
{
    /** Seeded by MaterialCatalogSeeder — term => canonical. */
    public const DEFAULTS = [
        'plywood' => 'triplek',
        'tripleks' => 'triplek',
        'multipleks' => 'multiplek',
        'multiplex' => 'multiplek',
        'aluminum' => 'aluminium',
        'alumunium' => 'aluminium',
    ];

    protected $fillable = ['term', 'canonical'];
}
