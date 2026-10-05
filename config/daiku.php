<?php

/*
 * Daiku business constants that more than one place must agree on.
 */

return [
    /*
     * PRD §6.5 daily task form — read by the scheduler (routes/console.php),
     * the submission cutoff (DailyTaskFormService) and the Tukang's
     * "Hari Ini" screen (Sprint 13 H2/H11) through App\Support\DailyFormSchedule.
     */
    'daily_form' => [
        // Penalty run (WIB) — also the last moment a form can be submitted.
        'penalty_at' => '21:00',
        // Reminder to the Tukang ("30 menit sebelum penalti", CSV Sprint 5).
        'reminder_at' => '20:30',
        // Working days, cron / Carbon day-of-week (0 = Minggu … 6 = Sabtu):
        // Senin–Sabtu (PRD §6.5 counts Saturday as a working day).
        'work_days' => [1, 2, 3, 4, 5, 6],
    ],
];
