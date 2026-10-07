<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Sprint 16 Sub 08 — Master Kota. A lead's city is picked from this list
 * (`CitySelect`), never typed; only the SUPERADMIN adds or renames rows in
 * Data Master. A city still used by a lead can't be deleted — rename it.
 */
class City extends Model
{
    public const RIAU = 'Riau';

    /** Seeded by CitySeeder (and used by the leads.city conversion): name → province. */
    public const DEFAULTS = [
        'Pekanbaru' => self::RIAU,
        'Dumai' => self::RIAU,
        'Kampar' => self::RIAU,
        'Bengkalis' => self::RIAU,
        'Siak' => self::RIAU,
        'Pelalawan' => self::RIAU,
        'Rokan Hulu' => self::RIAU,
        'Rokan Hilir' => self::RIAU,
        'Indragiri Hulu' => self::RIAU,
        'Indragiri Hilir' => self::RIAU,
        'Kuantan Singingi' => self::RIAU,
        'Kepulauan Meranti' => self::RIAU,
        'Batam' => 'Kepulauan Riau',
        'Tanjung Pinang' => 'Kepulauan Riau',
        'Padang' => 'Sumatera Barat',
        'Bukittinggi' => 'Sumatera Barat',
        'Medan' => 'Sumatera Utara',
        'Jambi' => 'Jambi',
        'Palembang' => 'Sumatera Selatan',
        'Jakarta' => 'DKI Jakarta',
    ];

    /**
     * Free-text spellings found in old `leads.city` values → master name
     * (lower-cased keys). Towns map to their kabupaten.
     */
    public const ALIASES = [
        'pku' => 'Pekanbaru',
        'kota pekanbaru' => 'Pekanbaru',
        'bangkinang' => 'Kampar',
        'duri' => 'Bengkalis',
        'siak sri indrapura' => 'Siak',
        'pangkalan kerinci' => 'Pelalawan',
        'pasir pengaraian' => 'Rokan Hulu',
        'bagan siapiapi' => 'Rokan Hilir',
        'rengat' => 'Indragiri Hulu',
        'tembilahan' => 'Indragiri Hilir',
        'teluk kuantan' => 'Kuantan Singingi',
        'selatpanjang' => 'Kepulauan Meranti',
        'tanjungpinang' => 'Tanjung Pinang',
        'dki jakarta' => 'Jakarta',
    ];

    protected $fillable = [
        'name',
        'province',
    ];

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /** The home city first, then Riau, then the other provinces — each by name. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderByRaw('name = ? DESC', [config('daiku.home_city')])
            ->orderByRaw('province = ? DESC', [self::RIAU])
            ->orderBy('province')
            ->orderBy('name');
    }

    /** @return Collection<int, array{id: int, name: string, province: ?string}> */
    public static function options(): Collection
    {
        return self::query()->ordered()->get(['id', 'name', 'province'])
            ->map(fn (City $city) => $city->only(['id', 'name', 'province']));
    }

    /** Seeder/factory helper: the master row for a name (created from DEFAULTS when missing). */
    public static function idFor(string $name): int
    {
        $name = self::canonicalName($name);

        return self::firstOrCreate(['name' => $name], ['province' => self::DEFAULTS[$name] ?? null])->id;
    }

    /**
     * Master name for a free-text spelling: "PKU" → "Pekanbaru",
     * "pekanbaru" → "Pekanbaru", an unknown "batu bara" → "Batu Bara".
     */
    public static function canonicalName(string $value): string
    {
        $key = mb_strtolower(preg_replace('/\s+/', ' ', trim($value)));

        if (isset(self::ALIASES[$key])) {
            return self::ALIASES[$key];
        }

        foreach (array_keys(self::DEFAULTS) as $name) {
            if (mb_strtolower($name) === $key) {
                return $name;
            }
        }

        return mb_convert_case($key, MB_CASE_TITLE);
    }
}
