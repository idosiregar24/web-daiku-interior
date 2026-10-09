<?php

namespace App\Support;

use App\Enums\NotificationType;
use App\Models\Notification;
use Illuminate\Support\Facades\Route;

/**
 * Sprint 18 Sub 02 — where opening a notification takes its recipient,
 * decided once on the server (moved from resources/js/lib/notificationHref.ts)
 * so the bell, the live toast and a device push (the service worker has no
 * Ziggy) all land on the same page via `notifications.open`.
 *
 * Built from the IDs the trigger put in `metadata`; only routes the
 * recipient's role can already open are targeted — the trigger picked its
 * recipients by the same RBAC rows, so no extra role check here. Anything
 * without a target lands on the notification history.
 */
class NotificationTarget
{
    /**
     * Staff-facing types whose actionable page is a list, not the resource:
     * a Field Staff `task_assigned` also carries project_id, but their
     * work is on the task list, not the project overview.
     */
    private const TYPE_ROUTES = [
        'task_assigned' => 'tasks.index',
        'task_updated' => 'tasks.index',
        'daily_form_reminder' => 'daily-forms.index',
        'penalty_issued' => 'penalties.index',
        'overtime_submitted' => 'overtime.index',
        'overtime_approved_pm' => 'overtime.index',
        'overtime_awaiting_finance' => 'overtime.index',
        'overtime_approved_finance' => 'overtime.index',
        'overtime_rejected' => 'overtime.index',
        'termin_reminder' => 'finance.termins.index',
        'termin_overdue' => 'finance.termins.index',
        'material_low_stock' => 'logistics.materials.index',
        'account_updated' => 'profile.edit',
    ];

    /**
     * Most specific resource first — a QA notification carries both
     * `qa_form_id` and `project_id` and belongs on the QA form; `lead_id`
     * rides along on design/deal notifications too, so it comes last.
     *
     * @var array<string, array{0: string, 1: string}> metadata key => [route, route parameter]
     */
    private const RESOURCE_ROUTES = [
        'qa_form_id' => ['qa-forms.show', 'qa_form'],
        'quotation_id' => ['quotations.show', 'quotation'],
        'design_id' => ['design.show', 'design'],
        'project_id' => ['projects.show', 'project'],
        'lead_id' => ['crm.leads.show', 'lead'],
    ];

    public static function for(Notification $notification): string
    {
        $metadata = $notification->metadata ?? [];

        return self::special($notification->notificationType(), $metadata)
            ?? self::typeRoute($notification->type)
            ?? self::resource($metadata)
            ?? route('notifications.index');
    }

    /**
     * SDM (Sprint 10) and invoice payment steps (Sprint 17 Sub 07) — the
     * target depends on who receives them or carries query parameters.
     *
     * @param  array<string, mixed>  $metadata
     */
    private static function special(?NotificationType $type, array $metadata): ?string
    {
        $id = fn (string $key): ?int => is_int($metadata[$key] ?? null) ? $metadata[$key] : null;

        return match ($type) {
            NotificationType::DisciplinaryIssued => route('my.index', ['tab' => 'discipline']),
            NotificationType::ReviewApproved => route('my.index', ['tab' => 'reviews']),
            NotificationType::SalaryChangeRequested => route('hr.salary.index'),
            NotificationType::SalaryChangeDecided => $id('employee_id')
                ? route('hr.employees.show', ['employee' => $id('employee_id'), 'tab' => 'salary'])
                : route('hr.salary.index'),
            NotificationType::ReviewSubmitted,
            NotificationType::ReviewApprovedReviewer,
            NotificationType::ReviewReturned => $id('performance_review_id')
                ? route('hr.reviews.show', ['performance_review' => $id('performance_review_id')])
                : route('hr.reviews.index'),
            // A rejected proof reopens "Kirim Bukti Bayar" on the invoice list.
            NotificationType::InvoiceRejected => route('finance.invoices.index', $id('invoice_id')
                ? ['awaiting_proof' => 1, 'proof' => $id('invoice_id')]
                : ['awaiting_proof' => 1]),
            NotificationType::InvoiceAwaitingVerification => route('finance.invoices.verification'),
            // Sprint 19 — straight into the "Jadwalkan Survey" dialog on the paid RAB.
            NotificationType::SurveyToSchedule => $id('quotation_id')
                ? route('quotations.show', ['quotation' => $id('quotation_id'), 'survey' => 'new'])
                : null,
            // Sprint 22 — straight into the "Kirim Desain ke Klien" dialog.
            NotificationType::DesignReadyToSend => $id('design_id')
                ? route('design.show', ['design' => $id('design_id'), 'action' => 'send'])
                : null,
            default => null,
        };
    }

    private static function typeRoute(string $type): ?string
    {
        $name = self::TYPE_ROUTES[$type] ?? null;

        return $name && Route::has($name) ? route($name) : null;
    }

    /** @param  array<string, mixed>  $metadata */
    private static function resource(array $metadata): ?string
    {
        foreach (self::RESOURCE_ROUTES as $key => [$name, $parameter]) {
            if (is_int($metadata[$key] ?? null)) {
                return route($name, [$parameter => $metadata[$key]]);
            }
        }

        return null;
    }
}
