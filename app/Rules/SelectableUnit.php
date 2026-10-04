<?php

namespace App\Rules;

use App\Models\Unit;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A unit from Master Satuan that may be picked: any active unit, plus
 * the ones the record being edited already uses — so deactivating a
 * unit (SUPERADMIN) never makes existing materials or RAB lines
 * impossible to save.
 */
class SelectableUnit implements ValidationRule
{
    /** @param  list<int|string|null>  $alreadyUsed */
    public function __construct(private array $alreadyUsed = []) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $unit = Unit::find($value);

        if (! $unit) {
            $fail('Satuan tidak ditemukan.');

            return;
        }

        if (! $unit->is_active && ! in_array($unit->id, array_map('intval', array_filter($this->alreadyUsed)), true)) {
            $fail("Satuan {$unit->code} sudah dinonaktifkan — pilih satuan lain.");
        }
    }
}
