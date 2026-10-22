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

export const inside = (day: string, start: string | null, end: string | null) => start !== null && end !== null && day >= start && day <= end;

