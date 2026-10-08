<?php

namespace App\Enums;

/**
 * Sprint 18 Sub 02 — every notification the system can send. The only
 * accepted `type` for NotificationService::notify*(); a new trigger adds a
 * case here and must pick its priority and category (NotificationTypeTest
 * fails otherwise). Values are the strings already stored in
 * `notifications.type`, so older rows keep resolving — see fromStored().
 */
enum NotificationType: string
{
    // CRM
    case LeadFollowUpDue = 'lead_follow_up_due';
    case LeadSurveyReady = 'lead_survey_ready';
    // Sprint 19 — a paid RAB Jasa Survey with no survey yet; the survey's
    // schedule told to the CEO and every PM.
    case SurveyToSchedule = 'survey_to_schedule';
    case SurveyScheduled = 'survey_scheduled';
    case SurveyRescheduled = 'survey_rescheduled';
    case SurveyCancelled = 'survey_cancelled';
    case SurveyConfirmed = 'survey_confirmed';
    case DealConfirmed = 'deal_confirmed';

    // Desain
    case DesignAcc = 'design_acc';
    case DesignAccPm = 'design_acc_pm';
    case DesignAssigned = 'design_assigned';
    case DesignAwaitingPayment = 'design_awaiting_payment';
    case DesignClientApproved = 'design_client_approved';
    case DesignDiscussion = 'design_discussion';
    case DesignReadyToAssign = 'design_ready_to_assign';
    case DesignRevisionRequested = 'design_revision_requested';
    case DesignSentToClient = 'design_sent_to_client';

    // RAB & penawaran
    case QuotationRequested = 'quotation_requested';
    case QuotationStarted = 'quotation_started';
    case QuotationSubmitted = 'quotation_submitted';
    case QuotationAwaitingCeo = 'quotation_awaiting_ceo';
    case QuotationReturned = 'quotation_returned';
    case QuotationApproved = 'quotation_approved';
    case QuotationReadyToSend = 'quotation_ready_to_send';
    case QuotationClientApproved = 'quotation_client_approved';
    case QuotationClientRejected = 'quotation_client_rejected';
    case QuotationCancelled = 'quotation_cancelled';
    case ProjectRabAutoRequested = 'project_rab_auto_requested';
    case ProjectRabAwaitingOpening = 'project_rab_awaiting_opening';

    // Invoice & termin
    case InvoiceToIssue = 'invoice_to_issue';
    case InvoiceAwaitingVerification = 'invoice_awaiting_verification';
    case InvoiceRejected = 'invoice_rejected';
    case InvoiceVerified = 'invoice_verified';
    case TerminInvoiceDue = 'termin_invoice_due';
    case TerminReminder = 'termin_reminder';
    case TerminOverdue = 'termin_overdue';

    // Proyek
    case ProjectOpeningPending = 'project_opening_pending';
    case ProjectOpened = 'project_opened';
    case ProjectAddendumAdded = 'project_addendum_added';
    case ProjectCompleted = 'project_completed';
    case ProjectPmAssigned = 'project_pm_assigned';
    case ProjectPmUnassigned = 'project_pm_unassigned';
    case ProjectAssistantAssigned = 'project_assistant_assigned';
    case ProjectAssistantUnassigned = 'project_assistant_unassigned';
    case MilestoneOverdue = 'milestone_overdue';
    case BudgetOverrunRequested = 'budget_overrun_requested';
    case BudgetOverrunApproved = 'budget_overrun_approved';
    case BudgetOverrunRejected = 'budget_overrun_rejected';

    // Tugas & form harian
    case TaskAssigned = 'task_assigned';
    case TaskOverdue = 'task_overdue';
    case TaskUpdated = 'task_updated';
    case DailyFormReminder = 'daily_form_reminder';
    case PenaltyIssued = 'penalty_issued';

    // Lembur
    case OvertimeSubmitted = 'overtime_submitted';
    case OvertimeApprovedPm = 'overtime_approved_pm';
    case OvertimeAwaitingFinance = 'overtime_awaiting_finance';
    case OvertimeApprovedFinance = 'overtime_approved_finance';
    case OvertimeRejected = 'overtime_rejected';

    // QA
    case QaFormCreated = 'qa_form_created';
    case QaApproved = 'qa_approved';
    case QaRejected = 'qa_rejected';
    case QaRejectedTwice = 'qa_rejected_twice';

    // Material & logistik
    case MaterialRequestSubmitted = 'material_request_submitted';
    case MaterialRequestPmPending = 'material_request_pm_pending';
    case MaterialRequestReminder = 'material_request_reminder';
    case MaterialRequestDecided = 'material_request_decided';
    case MaterialRequestSummary = 'material_request_summary';
    case MaterialLowStock = 'material_low_stock';
    case ProjectMaterialLeftover = 'project_material_leftover';

    // SDM
    case DisciplinaryIssued = 'disciplinary_issued';
    case SalaryChangeRequested = 'salary_change_requested';
    case SalaryChangeDecided = 'salary_change_decided';
    case ReviewSubmitted = 'review_submitted';
    case ReviewReturned = 'review_returned';
    case ReviewApproved = 'review_approved';
    case ReviewApprovedReviewer = 'review_approved_reviewer';

    // Akun (Sprint 21) — the CEO changed someone's username, email or password.
    case AccountUpdated = 'account_updated';

    /**
     * Strings written before a type was split (Sprint 18). Old
     * `quotation_rejected` rows meant either "returned by PM/CEO" or
     * "rejected by the client" — both P1 on the same quotation page.
     */
    private const LEGACY = [
        'quotation_rejected' => self::QuotationReturned,
    ];

    /** The type of a stored row, or null for a string no longer known (shown as plain Info). */
    public static function fromStored(string $value): ?self
    {
        return self::tryFrom($value) ?? self::LEGACY[$value] ?? null;
    }

    /**
     * Stored `type` strings of one priority, legacy ones included — for
     * queries (the bell lists unread P1 first).
     *
     * @return list<string>
     */
    public static function storedValuesOf(NotificationPriority $priority): array
    {
        $values = [];

        foreach (self::cases() as $case) {
            if ($case->priority() === $priority) {
                $values[] = $case->value;
            }
        }

        foreach (self::LEGACY as $legacy => $case) {
            if ($case->priority() === $priority) {
                $values[] = $legacy;
            }
        }

        return $values;
    }

    /** K3 mapping — see .claude/plan/sprint-18-notifikasi.md §3 "Prioritas". */
    public function priority(): NotificationPriority
    {
        return match ($this) {
            self::LeadFollowUpDue,
            self::QuotationRequested,
            self::QuotationSubmitted,
            self::QuotationAwaitingCeo,
            self::QuotationReturned,
            self::QuotationClientRejected,
            self::QuotationReadyToSend,
            self::InvoiceToIssue,
            self::InvoiceAwaitingVerification,
            self::InvoiceRejected,
            self::DesignRevisionRequested,
            self::DesignReadyToAssign,
            self::DesignAcc,
            self::LeadSurveyReady,
            self::SurveyToSchedule,
            self::ProjectOpeningPending => NotificationPriority::ClientWaiting,

            self::DesignAssigned,
            self::DesignDiscussion,
            self::TerminInvoiceDue,
            self::TerminOverdue,
            self::OvertimeSubmitted,
            self::OvertimeAwaitingFinance,
            self::BudgetOverrunRequested,
            self::MaterialRequestSubmitted,
            self::MaterialRequestPmPending,
            self::MaterialRequestReminder,
            self::MaterialLowStock,
            self::ProjectMaterialLeftover,
            self::QaFormCreated,
            self::QaRejected,
            self::QaRejectedTwice,
            self::TaskAssigned,
            self::TaskUpdated,
            self::DailyFormReminder,
            self::TaskOverdue,
            self::MilestoneOverdue,
            self::ProjectPmAssigned,
            self::ProjectAssistantAssigned,
            self::SalaryChangeRequested,
            self::ReviewSubmitted,
            self::ReviewReturned,
            // Sprint 19 (K3) — Marketing hears the money arrived with a ring.
            self::InvoiceVerified,
            self::SurveyScheduled,
            self::SurveyRescheduled,
            self::SurveyCancelled => NotificationPriority::ActionRequired,

            self::QuotationApproved,
            self::QuotationCancelled,
            // Sprint 18 Sub 06: the follow-up that needs doing arrives as
            // its own P1 (invoice_to_issue / project_opening_pending) —
            // this one only reports the client's ACC, so a phone rings once.
            self::QuotationClientApproved,
            self::SurveyConfirmed,
            self::TerminReminder,
            self::DealConfirmed,
            self::ProjectOpened,
            self::ProjectAddendumAdded,
            self::DesignSentToClient,
            self::DesignClientApproved,
            self::DesignAccPm,
            self::OvertimeApprovedPm,
            self::OvertimeApprovedFinance,
            self::OvertimeRejected,
            self::BudgetOverrunApproved,
            self::BudgetOverrunRejected,
            self::MaterialRequestDecided,
            self::QaApproved,
            self::PenaltyIssued,
            self::DisciplinaryIssued,
            self::SalaryChangeDecided,
            self::ReviewApprovedReviewer,
            self::AccountUpdated => NotificationPriority::Update,

            self::QuotationStarted,
            self::ProjectRabAutoRequested,
            self::ProjectRabAwaitingOpening,
            self::DesignAwaitingPayment,
            self::ProjectCompleted,
            self::ProjectPmUnassigned,
            self::ProjectAssistantUnassigned,
            self::MaterialRequestSummary,
            self::ReviewApproved => NotificationPriority::Info,
        };
    }

    public function category(): NotificationCategory
    {
        return match ($this) {
            self::LeadFollowUpDue,
            self::LeadSurveyReady,
            self::SurveyToSchedule,
            self::SurveyScheduled,
            self::SurveyRescheduled,
            self::SurveyCancelled,
            self::SurveyConfirmed,
            self::DealConfirmed => NotificationCategory::Crm,

            self::DesignAcc,
            self::DesignAccPm,
            self::DesignAssigned,
            self::DesignAwaitingPayment,
            self::DesignClientApproved,
            self::DesignDiscussion,
            self::DesignReadyToAssign,
            self::DesignRevisionRequested,
            self::DesignSentToClient => NotificationCategory::Design,

            self::QuotationRequested,
            self::QuotationStarted,
            self::QuotationSubmitted,
            self::QuotationAwaitingCeo,
            self::QuotationReturned,
            self::QuotationApproved,
            self::QuotationReadyToSend,
            self::QuotationClientApproved,
            self::QuotationClientRejected,
            self::QuotationCancelled,
            self::ProjectRabAutoRequested,
            self::ProjectRabAwaitingOpening => NotificationCategory::Quotation,

            self::InvoiceToIssue,
            self::InvoiceAwaitingVerification,
            self::InvoiceRejected,
            self::InvoiceVerified,
            self::TerminInvoiceDue,
            self::TerminReminder,
            self::TerminOverdue => NotificationCategory::Finance,

            self::ProjectOpeningPending,
            self::ProjectOpened,
            self::ProjectAddendumAdded,
            self::ProjectCompleted,
            self::ProjectPmAssigned,
            self::ProjectPmUnassigned,
            self::ProjectAssistantAssigned,
            self::ProjectAssistantUnassigned,
            self::MilestoneOverdue,
            self::BudgetOverrunRequested,
            self::BudgetOverrunApproved,
            self::BudgetOverrunRejected => NotificationCategory::Project,

            self::TaskAssigned,
            self::TaskOverdue,
            self::TaskUpdated,
            self::DailyFormReminder,
            self::PenaltyIssued => NotificationCategory::Task,

            self::OvertimeSubmitted,
            self::OvertimeApprovedPm,
            self::OvertimeAwaitingFinance,
            self::OvertimeApprovedFinance,
            self::OvertimeRejected => NotificationCategory::Overtime,

            self::QaFormCreated,
            self::QaApproved,
            self::QaRejected,
            self::QaRejectedTwice => NotificationCategory::Qa,

            self::MaterialRequestSubmitted,
            self::MaterialRequestPmPending,
            self::MaterialRequestReminder,
            self::MaterialRequestDecided,
            self::MaterialRequestSummary,
            self::MaterialLowStock,
            self::ProjectMaterialLeftover => NotificationCategory::Logistics,

            self::DisciplinaryIssued,
            self::SalaryChangeRequested,
            self::SalaryChangeDecided,
            self::ReviewSubmitted,
            self::ReviewReturned,
            self::ReviewApproved,
            self::ReviewApprovedReviewer => NotificationCategory::Hr,

            self::AccountUpdated => NotificationCategory::Account,
        };
    }
}
