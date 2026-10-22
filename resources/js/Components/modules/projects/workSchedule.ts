import type { WorkItemScheduleStatus } from '@/types';
import { addDays, differenceInCalendarDays, format, parseISO } from 'date-fns';
import { id as localeId } from 'date-fns/locale';

/** Sprint 31 — WorkItemService::scheduleStatus(), worded once for the Lembar Progres. */
export const SCHEDULE_STATUS: Record<WorkItemScheduleStatus, { label: string; tone: 'neutral' | 'info' | 'error' | 'success' }> = {
    BELUM_DIJADWALKAN: { label: 'Belum dijadwalkan', tone: 'neutral' },
    AKAN_DATANG: { label: 'Akan datang', tone: 'neutral' },
    BERJALAN: { label: 'Berjalan', tone: 'info' },
    TERLAMBAT: { label: 'Terlambat', tone: 'error' },
    SELESAI: { label: 'Selesai', tone: 'success' },
};

/** "2 Okt", "2–5 Okt", "28 Sep – 3 Okt"; "—" without both dates. */
export function rangeLabel(start: string | null | undefined, end: string | null | undefined): string {
    if (!start || !end) return '—';
    const from = parseISO(start);
    const to = parseISO(end);
    if (start === end) return format(from, 'd MMM', { locale: localeId });

    return from.getMonth() === to.getMonth()
        ? `${format(from, 'd')}–${format(to, 'd MMM', { locale: localeId })}`
        : `${format(from, 'd MMM', { locale: localeId })} – ${format(to, 'd MMM', { locale: localeId })}`;
}

/** Every day from `from` to `to` (Y-m-d), inclusive. */
export function dayRange(from: string, to: string): string[] {
    const start = parseISO(from);
    const count = differenceInCalendarDays(parseISO(to), start) + 1;

    return Array.from({ length: Math.max(0, count) }, (_, index) => format(addDays(start, index), 'yyyy-MM-dd'));
}

/** `start` + `days` − 1, Y-m-d — the end of a window that lasts `days` days. */
export function endOf(start: string, days: number): string {
    return format(addDays(parseISO(start), Math.max(1, days) - 1), 'yyyy-MM-dd');
}

/** Length in days of a window (1 for a single day); null without both dates. */
export function lengthOf(start: string | null | undefined, end: string | null | undefined): number | null {
    return start && end ? differenceInCalendarDays(parseISO(end), parseISO(start)) + 1 : null;
}

export const inside = (day: string, start: string | null, end: string | null) => start !== null && end !== null && day >= start && day <= end;

type ScheduleItem = {
    id: number;
    description: string;
    production_start: string | null;
    production_end: string | null;
};

/** `day` + `count` days, Y-m-d. */
export function plusDays(day: string, count: number): string {
    return format(addDays(parseISO(day), count), 'yyyy-MM-dd');
}

/**
 * Sprint 33 — where an item's production most likely starts: the day after
 * the nearest earlier item (RAB order) ends — the staircase of the sheet —
 * else the project's start, else today. Kept inside the project's dates.
 */
export function suggestStart(
    items: ScheduleItem[],
    itemId: number,
    bounds: {
        project_start: string | null;
        project_end: string | null;
        today: string;
    },
): { date: string; reason: string; length: number | null } {
    const index = items.findIndex((item) => item.id === itemId);
    const previous = items
        .slice(0, Math.max(0, index))
        .reverse()
        .find((item) => item.production_start && item.production_end);
    const fallback = bounds.project_start ?? bounds.today;

    if (previous?.production_end) {
        const next = plusDays(previous.production_end, 1);
        if (!bounds.project_end || next <= bounds.project_end) {
            return {
                date: next,
                reason: `setelah ${previous.description} selesai`,
                length: lengthOf(previous.production_start, previous.production_end),
            };
        }
    }

    return {
        date: fallback,
        reason: bounds.project_start ? 'awal proyek' : 'hari ini',
        length: null,
    };
}

/** "Senin, 27 Okt" */
export function dayLabel(day: string): string {
    return format(parseISO(day), 'EEEE, d MMM', { locale: localeId });
}
