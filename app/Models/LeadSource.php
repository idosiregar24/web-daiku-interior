<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadSource extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    /**
     * `leads.source` still mirrors this row's name (kept for the CRM
     * dashboard's string grouping — see LeadService), so a rename must
     * propagate or the dashboard and the FK-based filters disagree.
     */
    protected static function booted(): void
    {
        static::updated(function (self $row) {
            if ($row->wasChanged('name')) {
                $row->leads()->update(['source' => $row->name]);
            }
        });
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * Case-insensitive lookup by name, creating the row if missing — used
     * to translate legacy free-string input (older callers, demo seeders)
     * into a Data Master row.
     */
    public static function findOrCreateByName(string $name): self
    {
        $name = trim($name);

        return static::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first()
            ?? static::create(['name' => $name]);
    }
}
