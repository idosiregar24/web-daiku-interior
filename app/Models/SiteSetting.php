<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    protected $fillable = [
        'site_name',
        'company_address',
        'company_phone',
        'company_email',
        'company_logo_url',
    ];

    /**
     * Site settings are a singleton — there is always exactly one row.
     * Get it, creating it with defaults on first use (e.g. right after a
     * fresh migrate before any seeder has run).
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }
}
