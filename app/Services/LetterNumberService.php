<?php

namespace App\Services;

use App\Models\LetterSequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 15 K2 — the company's letter numbers: `377/OFF/Daiku/IX/2026` for
 * an offer (penawaran), `378/INV/Daiku/IX/2026` for an invoice. One running
 * number per calendar year shared by both codes (as the company numbers
 * its outgoing letters), Roman month of the letter's date.
 */
class LetterNumberService
{
    public const OFFER = 'OFF';

    public const INVOICE = 'INV';

    private const ROMAN = [1 => 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

    public function next(string $code, ?Carbon $date = null): string
    {
        $date ??= now('Asia/Jakarta');

        $number = DB::transaction(function () use ($date) {
            $sequence = LetterSequence::query()->lockForUpdate()->find($date->year)
                ?? LetterSequence::create(['year' => $date->year, 'last_number' => 0]);

            $sequence->increment('last_number');

            return $sequence->last_number;
        });

        return implode('/', [$number, $code, config('daiku.letter.company_code', 'Daiku'), self::ROMAN[$date->month], $date->year]);
    }
}
