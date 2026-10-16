<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Sprint 13 — the daily-form clock in one place (config/daiku.php): the
 * scheduler, the submission cutoff and the "Hari Ini" screen all ask
 * here, so a change of hour or working days can't drift between them.
 */
final class DailyFormSchedule
{
    public const TIMEZONE = 'Asia/Jakarta';

    /** "21:00" — penalty run and submission cutoff (WIB). */
    public static function penaltyAt(): string
    {
        return (string) config('daiku.daily_form.penalty_at', '21:00');
    }

    public static function reminderAt(): string
    {
        return (string) config('daiku.daily_form.reminder_at', '20:30');
    }

    /** @return list<int> day-of-week, 0 = Minggu … 6 = Sabtu */
    public static function workDays(): array
    {
        return array_values(array_map('intval', (array) config('daiku.daily_form.work_days', [1, 2, 3, 4, 5, 6])));
    }

    /** A day the form is due and missing it is penalised (Senin–Sabtu). */
    public static function isWorkDay(?Carbon $at = null): bool
    {
        return in_array(($at ?? self::now())->dayOfWeek, self::workDays(), true);
    }

    /** Past the cutoff: the form can no longer be submitted today. */
    public static function isPastCutoff(?Carbon $at = null): bool
    {
        return ($at ?? self::now())->format('H:i') >= self::penaltyAt();
    }

    /**
     * Sprint 24 D8 — the first working day on or after `$from` (`$after`:
     * strictly after it) — where an unfinished daily target rolls to.
     */
    public static function nextWorkDay(Carbon $from, bool $after = false): Carbon
    {
        $day = $from->copy()->startOfDay();

        if ($after) {
            $day->addDay();
        }

        // workDays() is never empty in practice; the bound only guards a broken config.
        for ($i = 0; $i < 7 && ! self::isWorkDay($day); $i++) {
            $day->addDay();
        }

        return $day;
    }

    public static function now(): Carbon
    {
        return now(self::TIMEZONE);
    }
}
