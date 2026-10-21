import { quietSuccess } from '@/hooks/useFlashToasts';
import type { ManualTaskStatus } from '@/lib/taskLabels';
import { router } from '@inertiajs/react';
import { useCallback, useMemo, useRef, useState } from 'react';
import type { FieldTask } from './TaskCard';

/**
 * Sprint 29 Sub 01 (L3–L7) — how a task save reloads the page it was made
 * on. The task components are shared by Detail Proyek, Tugas and Hari Ini,
 * whose props differ, so the caller names the props a task save changes
 * (`reload`): Detail Proyek passes `RELOAD.tasks`, Tugas `TASKS_INDEX_RELOAD`,
 * Hari Ini `TODAY_RELOAD`. Without `reload` the save reloads the whole page,
 * as before.
 */
export type TaskReload = readonly string[];

/** Pages/Tasks/Index — the list is the only prop a task save changes. */
export const TASKS_INDEX_RELOAD: TaskReload = ['tasks'];

/** Pages/Today/Index — the cards, next week's cards and the "belum dilaporkan" count. */
export const TODAY_RELOAD: TaskReload = ['tasks', 'upcoming', 'missingToday'];

interface TaskVisitOptions {
    /** Repeated input: no centred success pop-up — the changed card/row is the feedback (K4). */
    quiet?: boolean;
}

/**
 * Visit options for a task save: `router.patch(url, data, { ...taskVisit(reload), onSuccess })`.
 * With `reload` the visit is partial (+ the shared `flash` and `navBadges`),
 * so the global "Menyimpan…" overlay stays away and the button shows its own
 * spinner (K6).
 */
export function taskVisit(reload: TaskReload | undefined, { quiet = false }: TaskVisitOptions = {}) {
    return {
        preserveScroll: true,
        ...(reload ? { only: [...reload, 'flash', 'navBadges'], preserveState: true } : {}),
        ...(quiet ? quietSuccess : {}),
    };
}

/** All a Tukang's own save may send — never title, description or due date (PRD §4.5). */
export interface TaskStatusPayload {
    status: ManualTaskStatus;
    kendala?: string | null;
    note?: string | null;
}

export interface TaskStatusSaveCallbacks {
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
    /** Always, once the server answered (or the visit failed). */
    onFinish?: () => void;
}

/** Returns false when this task's previous save is still on its way (nothing sent). */
export type SaveTaskStatus = (task: FieldTask, payload: TaskStatusPayload, callbacks?: TaskStatusSaveCallbacks) => boolean;

/**
 * L3 — the Tukang's "centang" without waiting: the card shows the new status
 * at once (a local override per task id), its button locks with a spinner,
 * only the page's task props are reloaded, and the override is dropped when
 * the server answers — by then the props carry the saved task, or, when the
 * save was refused, the card falls back to its old status (the refusal is
 * toasted by useFlashToasts).
 */
export function useTaskStatusSave<T extends FieldTask>(tasks: T[], reload?: TaskReload) {
    const [overrides, setOverrides] = useState<Record<number, Partial<FieldTask>>>({});
    // Ids on their way — a ref, so a second tap before the re-render is still refused.
    const inflight = useRef(new Set<number>());

    const items = useMemo(() => tasks.map((task) => (overrides[task.id] ? { ...task, ...overrides[task.id] } : task)), [tasks, overrides]);

    const isSaving = useCallback((task: Pick<FieldTask, 'id'>) => task.id in overrides, [overrides]);

    const save = useCallback<SaveTaskStatus>(
        (task, payload, callbacks = {}) => {
            if (inflight.current.has(task.id)) return false;
            inflight.current.add(task.id);

            setOverrides((current) => ({
                ...current,
                [task.id]: {
                    // Sprint 29 Sub 04 (F1) — the Tukang's "Selesai" waits for the Pengawas.
                    status: payload.status === 'DONE' ? 'PENGECEKAN' : payload.status,
                    ...('kendala' in payload ? { kendala: payload.kendala ?? null } : {}),
                    ...('note' in payload ? { note: payload.note ?? null } : {}),
                    // Sprint 24 D7 — the Tukang's own update is the day's report.
                    ...(task.has_form_today === false ? { has_form_today: true } : {}),
                },
            }));

            router.patch(route('tasks.updateStatus', { task: task.id }), { ...payload }, {
                ...taskVisit(reload, { quiet: true }),
                onSuccess: () => callbacks.onSuccess?.(),
                onError: (errors) => callbacks.onError?.(errors),
                onFinish: () => {
                    inflight.current.delete(task.id);
                    setOverrides((current) => {
                        const { [task.id]: _done, ...rest } = current;

                        return rest;
                    });
                    callbacks.onFinish?.();
                },
            });

            return true;
        },
        [reload],
    );

    /** Tasks whose save is still on its way and was not reported today yet (the optimistic "belum dilaporkan" count). */
    const pendingReports = useMemo(() => tasks.filter((task) => task.id in overrides && task.has_form_today === false).length, [tasks, overrides]);

    return { items, isSaving, save, pendingReports };
}
