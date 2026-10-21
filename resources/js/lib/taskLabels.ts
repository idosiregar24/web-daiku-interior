import type { TaskPriority, TaskStatus } from '@/types';

/**
 * Sprint 28 K4 — the one set of words for a task's status and priority.
 * The Tukang's own words ("Menunggu dicek"), used by the PM's "Ubah Status"
 * dialog, the Tukang's action sheet, `<StatusChip domain="task">`, the
 * Kanban columns and every task table — never a per-page copy.
 */
export const TASK_STATUS_LABEL: Record<TaskStatus, string> = {
    PENDING: 'Belum mulai',
    ONPROGRESS: 'Dikerjakan',
    // Sprint 29 Sub 04 — the Tukang's "Selesai" waits for the Pengawas.
    PENGECEKAN: 'Menunggu dicek',
    DONE: 'Selesai',
    OVER: 'Terlambat',
};

/** OVER is system-computed only (PRD §4.5) — same list as UpdateTaskStatusRequest / StoreDailyTaskFormRequest. */
export type ManualTaskStatus = Exclude<TaskStatus, 'OVER'>;

export const MANUAL_TASK_STATUSES: ManualTaskStatus[] = ['PENDING', 'ONPROGRESS', 'PENGECEKAN', 'DONE'];

export const TASK_PRIORITY_LABEL: Record<TaskPriority, string> = {
    HIGH: 'Tinggi',
    MEDIUM: 'Sedang',
    LOW: 'Rendah',
};
