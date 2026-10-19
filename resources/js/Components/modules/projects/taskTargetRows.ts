import type { Task } from '@/types';
import { differenceInCalendarDays, format, startOfWeek } from 'date-fns';

/** How a daily target turned out — the right-hand ("Actual") column. */
export type TaskTargetState = 'done' | 'over' | 'rolled' | 'pending' | 'upcoming';

export interface TaskTargetRow {
    task: Task;
    /** The day it was planned for (`planned_date ?? due_date`) — never moves on rollover. */
    targetOn: string | null;
    /** The WIB day it was finished, DONE only. */
    doneOn: string | null;
    /** "14.05" WIB, DONE only. */
    doneAt: string | null;
    /** doneOn − targetOn in calendar days (negative = earlier). */
    daysLate: number | null;
    rolled: number;
    state: TaskTargetState;
}

export interface TaskTargetWeek {
    /** Monday, Y-m-d — `null` for targets without a date. */
    start: string | null;
    days: { date: string | null; rows: TaskTargetRow[] }[];
    total: number;
    done: number;
}

// Sprint 24 lesson: a date-only/UTC value read in the browser's zone shifts a day —
// the actual day is the WIB day (ProductionService reads completed_at in DailyFormSchedule::TIMEZONE).
const WIB_DAY = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit' });
const WIB_TIME = new Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Jakarta', hour: '2-digit', minute: '2-digit' });

export const asDay = (day: string) => new Date(`${day}T00:00:00`);

/** Today in WIB, Y-m-d. */
export function todayWib(): string {
    return WIB_DAY.format(new Date());
}

/**
 * Sprint 27 P5 — the whiteboard's "target – Aktivity / Actual" per task:
 * every daily target with the day it was planned for (left) and what
 * actually happened (right). Same definitions as the Activity Target
 * numbers (Sprint 25 A5): target day = `planned_date ?? due_date`, actual
 * = the WIB day of `completed_at` on a DONE task, rolled = `rollover_count`.
 */
export function taskTargetRows(tasks: Task[], today: string = todayWib()): TaskTargetRow[] {
    return tasks.map((task) => {
        const targetOn = task.planned_date ?? task.due_date ?? null;
        const done = task.status === 'DONE' && task.completed_at !== null;
        const finished = done ? new Date(task.completed_at as string) : null;
        const doneOn = finished ? WIB_DAY.format(finished) : null;
        const rolled = task.rollover_count ?? 0;

        let state: TaskTargetState;
        if (done) {
            state = 'done';
        } else if (task.status === 'OVER') {
            state = 'over';
        } else if (rolled > 0) {
            state = 'rolled';
        } else if (targetOn !== null && targetOn > today) {
            state = 'upcoming';
        } else {
            state = 'pending';
        }

        return {
            task,
            targetOn,
            doneOn,
            doneAt: finished ? WIB_TIME.format(finished) : null,
            daysLate: doneOn && targetOn ? differenceInCalendarDays(asDay(doneOn), asDay(targetOn)) : null,
            rolled,
            state,
        };
    });
}

/** Rows grouped per week (Monday) and target day, oldest first; undated targets last. */
export function groupTaskTargets(rows: TaskTargetRow[]): TaskTargetWeek[] {
    const weeks = new Map<string, Map<string, TaskTargetRow[]>>();

    for (const row of rows) {
        const week = row.targetOn ? format(startOfWeek(asDay(row.targetOn), { weekStartsOn: 1 }), 'yyyy-MM-dd') : '';
        const day = row.targetOn ?? '';
        if (!weeks.has(week)) weeks.set(week, new Map());
        const days = weeks.get(week)!;
        if (!days.has(day)) days.set(day, []);
        days.get(day)!.push(row);
    }

    const order = (a: string, b: string) => (a === '' ? 1 : b === '' ? -1 : a.localeCompare(b));

    return [...weeks.entries()]
        .sort(([a], [b]) => order(a, b))
        .map(([start, days]) => {
            const list = [...days.entries()]
                .sort(([a], [b]) => order(a, b))
                .map(([date, dayRows]) => ({
                    date: date || null,
                    rows: dayRows.sort((x, y) => (x.task.assignee?.name ?? '').localeCompare(y.task.assignee?.name ?? '') || x.task.id - y.task.id),
                }));
            const all = list.flatMap((day) => day.rows);

            return { start: start || null, days: list, total: all.length, done: all.filter((row) => row.state === 'done').length };
        });
}
