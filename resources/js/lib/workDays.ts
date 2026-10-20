/**
 * Sprint 28 K13 — the days a daily target may be moved to ("Geser"/"Tukar").
 * The server's rule is App\Support\DailyFormSchedule::isWorkDay()
 * (config daiku.daily_form.work_days, Senin–Sabtu); the working days are
 * not shared to the pages, so the client only mirrors the default — Minggu
 * is not a working day — and the server always checks again.
 */
export const NON_WORK_DAY_MESSAGE = 'Hari Minggu bukan hari kerja — pilih Senin–Sabtu.';

/** `value` is a `yyyy-MM-dd` date. */
export function isWorkDay(value: string): boolean {
    return new Date(`${value.slice(0, 10)}T00:00:00`).getDay() !== 0;
}
