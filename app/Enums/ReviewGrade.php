<?php

namespace App\Enums;

/** SDM (Sprint 10, §3.4) — derived from a review's final score (0–100+). */
enum ReviewGrade: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';
    case E = 'E';

    public static function fromScore(float $score): self
    {
        return match (true) {
            $score >= 90 => self::A,
            $score >= 80 => self::B,
            $score >= 70 => self::C,
            $score >= 60 => self::D,
            default => self::E,
        };
    }
}
