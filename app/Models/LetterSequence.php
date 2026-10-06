<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Sprint 15 K2 — last letter number used per year (LetterNumberService). */
class LetterSequence extends Model
{
    protected $primaryKey = 'year';

    public $incrementing = false;

    protected $fillable = ['year', 'last_number'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'last_number' => 'integer',
        ];
    }
}
