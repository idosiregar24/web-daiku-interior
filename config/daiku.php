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

    /*
     * Sprint 18 Sub 06 (K3) — an unopened "klien menunggu" (P1) notification
     * rings the devices once more after `after_minutes`, only on working
     * days (daily_form.work_days) between `from` and `until` WIB. Older than
     * `max_age_hours` is left to "Perlu Tindakan" (RepushClientWaitingJob).
     */
    'notification_repush' => [
        'after_minutes' => 60,
        'from' => '08:00',
        'until' => '17:00',
        'max_age_hours' => 24,
    ],

    /*
     * Sprint 15 K2 — letter numbers "377/OFF/Daiku/IX/2026" (LetterNumberService).
     */
    'letter' => [
        'company_code' => env('LETTER_COMPANY_CODE', 'Daiku'),
    ],

    /*
     * Sprint 16 Sub 08 (K15) — the company's own city. A survey for a lead
     * whose Master Kota is anything else defaults to "Luar Pekanbaru"
     * (Lead::is_outside_home_city); CitySelect lists it first.
     */
    'home_city' => 'Pekanbaru',

    /*
     * Sprint 20 D6 — the public company profile at `/`. While `placeholders`
     * is on, an empty Profil Publik column / an empty portfolio or
     * testimonial list shows the stand-ins of
     * App\Support\CompanyProfile\Placeholder; off (launch), the section is
     * hidden instead. Turn it off once the real material is in.
     */
    'company_profile' => [
        'placeholders' => (bool) env('SITE_PLACEHOLDERS', true),
    ],

    /*
     * Sprint 21 (K4) — a user may change their own username once per this
     * many days (UserService::changeOwnUsername()). The CEO isn't limited.
     */
    'username_change_days' => 40,
];
