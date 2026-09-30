import type { AppNotification } from '@/types';
import { router } from '@inertiajs/react';

/**
 * Where clicking a notification should go, derived from the IDs its
 * trigger put in `metadata` (see NotificationService callers). Most
 * specific resource first — a QA notification carries both
 * `qa_form_id` and `project_id`, and belongs on the QA form; `lead_id`
 * rides along on design/deal notifications too, so it comes last. Only
 * routes the recipient role can already open are targeted (the trigger
 * picked recipients by the same RBAC rows), so no extra role check here.
 */
const TARGETS: [key: string, build: (id: number) => string][] = [
    ['qa_form_id', (id) => route('qa-forms.show', { qa_form: id })],
    ['quotation_id', (id) => route('quotations.show', { quotation: id })],
    ['design_id', (id) => route('design.show', { design: id })],
    ['project_id', (id) => route('projects.show', { project: id })],
    ['lead_id', (id) => route('crm.leads.show', { lead: id })],
];

const TYPE_FALLBACKS: Record<string, string> = {
    task_assigned: 'tasks.index',
    daily_form_reminder: 'daily-forms.index',
    penalty_issued: 'penalties.index',
    overtime_submitted: 'overtime.index',
    overtime_approved_pm: 'overtime.index',
    overtime_approved_finance: 'overtime.index',
    overtime_rejected: 'overtime.index',
    termin_reminder: 'finance.termins.index',
    termin_overdue: 'finance.termins.index',
    material_low_stock: 'logistics.materials.index',
};

export function notificationHref(notification: AppNotification): string | null {
    const typeRoute = TYPE_FALLBACKS[notification.type];

    // Type-specific pages win for staff-facing types: a Field Staff
    // "task_assigned" also carries project_id, but their actionable view
    // is the task list, not the project overview.
    if (typeRoute && route().has(typeRoute)) {
        return route(typeRoute);
    }

    for (const [key, build] of TARGETS) {
        const id = notification.metadata?.[key];

        if (typeof id === 'number') {
            return build(id);
        }
    }

    return null;
}

/**
 * Click handler shared by the topbar bell, the Dashboard widget and the
 * notification history: mark it read (if it isn't yet), then go to its
 * target page.
 */
export function openNotification(notification: AppNotification): void {
    const href = notificationHref(notification);
    const visit = () => href && router.visit(href);

    if (notification.is_read) {
        visit();

        return;
    }

    router.patch(
        route('notifications.markAsRead', { notification: notification.id }),
        {},
        { preserveScroll: true, onSuccess: visit },
    );
}
