// Shared TypeScript interfaces for the Daiku Interior system.
// Mirrors the roles, enums and entities defined in PRD section 5 (Database
// Schema & Entities) and section 7 (RBAC). Extend per-module as controllers
// and Inertia pages are built out.

/**
 * PRD 2 — Stakeholders & Users. `SUPERADMIN` (technical admin) and `HR`
 * (SDM, Sprint 10) are added outside the PRD — see database/seeders/RoleSeeder.php.
 */
export type Role =
    | 'CEO'
    | 'MARKETING'
    | 'DESIGNER'
    | 'ESTIMATOR'
    | 'PM'
    | 'QA'
    | 'FINANCE'
    | 'LOGISTICS'
    | 'FIELD_STAFF'
    | 'SUPERADMIN'
    | 'HR'
    // Sprint 12 (outside the PRD): the PM's assistant; Kepala Desain is stacked on DESIGNER.
    | 'ASISTEN_PM'
    | 'KEPALA_DESAIN';

export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    /** Primary role — what nav gating and role checks read (never a stacked role). */
    role?: Role;
    /** Every role held (shared auth user only). */
    roles?: Role[];
    /** The role to label the user with — Kepala Desain over Desainer (shared auth user only). */
    display_role?: Role | null;
    is_active?: boolean;
    /** SDM: linked to an active employee row — the "Milik Saya" menu shows only then. */
    has_employee?: boolean;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    /** Latest 10 unread — shared on every visit and partial-reloaded live by AppLayout's bell (see HandleInertiaRequests). */
    notifications: AppNotification[];
    unreadNotificationsCount: number;
    /** Session flash from `back()->with('success', ...)` — AppLayout toasts it once per `key`. */
    flash: { success: string | null; error: string | null; key: string } | null;
    /** Web customization from Pengaturan Situs (name, logo, login page…). */
    site: SiteBranding;
    /** Sprint 12 #19 — CEO only: approved RAB Proyek waiting for "Buka Proyek" (null when none / not CEO). */
    pendingProjectOpenings?: PendingProjectOpenings | null;
};

/** Sprint 12 #19 — App\Models\ProjectOpening. */
export interface ProjectOpening {
    id: number;
    quotation_id: number;
    lead_id: number;
    status: 'MENUNGGU_CEO' | 'DIBUKA';
    lead: { id: number; client_name: string };
    quotation: { id: number; total_amount: string; version: number; client_approved_at: string | null };
    created_at: string;
}

export interface PendingProjectOpenings {
    openings: ProjectOpening[];
    projectManagers: { id: number; name: string }[];
    assistantPms: { id: number; name: string }[];
}

/** Shape of a Laravel paginator (`->paginate()`) as sent to Inertia props. */
export interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    /** 1-based index of the first/last row on this page — null when the page is empty. */
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
}

/** PRD 4.1 — CRM / Presales */
export type LeadPriority = 'HOT' | 'WARM' | 'COLD';
export type LeadStatus = 'FOLLOW_UP' | 'DEAL_DESAIN' | 'CLOSING' | 'LOST';
export type LeadCategory =
    | 'RESIDENTIAL'
    | 'KOMERSIAL'
    | 'DEVELOPER'
    | 'KONTRAKTOR'
    | 'LAINNYA';

export interface Lead {
    id: number;
    client_name: string;
    contact: string;
    source: string;
    priority: LeadPriority;
    /** Legacy string mirror of `lead_category.name` (kept in sync by LeadService) — any Data Master name. */
    category: string | null;
    /** FK into Data Master (Sprint 8). The legacy `source`/`category` strings are kept during the transition. */
    lead_source_id: number | null;
    lead_category_id: number | null;
    lead_source?: Pick<LeadSourceOption, 'id' | 'name'> | null;
    lead_category?: Pick<LeadCategoryOption, 'id' | 'name'> | null;
    service: string | null;
    city: string | null;
    gender: string | null;
    order_detail: string | null;
    status: LeadStatus;
    assigned_to: number;
    /** Loaded via `with('assignee:id,name')` — see Lead::assignee() for why it isn't named `assignedTo`. */
    assignee?: Pick<User, 'id' | 'name'>;
    creator?: Pick<User, 'id' | 'name'>;
    /** Loaded via `with('design:id,lead_id')` — presence alone tells the CRM index whether to show "Buka Desain" or "Lihat Desain". */
    design?: Pick<Design, 'id'> | null;
    /** CRM index/detail — the offer's state drives "Konfirmasi Deal" / "Klien Menolak Penawaran" and the expiry warning. */
    quotation?: Pick<Quotation, 'id' | 'status' | 'valid_until' | 'version'> | null;
    /** Sprint 12 #1 — when Marketing first contacted the client (`created_at` = entered the system). */
    first_contacted_at: string | null;
    /** Sprint 12 #4 — general address + Google Maps link (http/https). */
    address: string | null;
    maps_url: string | null;
    /** Lead::scopeWithNextFollowUp() — earliest open follow-up, and how many FUs exist. */
    next_follow_up_date?: string | null;
    follow_ups_count?: number;
    /** Lead detail — the FU-n and survey timeline (Sprint 12 #2–#3). */
    follow_ups?: LeadFollowUp[];
    surveys?: LeadSurvey[];
    lost_reason: string | null;
    notes: string | null;
    created_by: number;
    created_at: string;
    updated_at: string;
}

/** Sprint 12 decision #2 — one numbered follow-up (FU-n) of a lead. */
export interface LeadFollowUp {
    id: number;
    lead_id: number;
    sequence: number;
    scheduled_date: string;
    done_at: string | null;
    result_note: string | null;
    creator?: Pick<User, 'id' | 'name'> | null;
    created_at: string;
}

/** Sprint 12 decision #3 — App\Enums\LeadSurveyStatus. */
export type LeadSurveyStatus = 'DIJADWALKAN' | 'MENUNGGU_BAYAR' | 'SIAP' | 'SELESAI' | 'BATAL';

/** Sprint 12 decision #3 — a site survey of a lead (may repeat). */
export interface LeadSurvey {
    id: number;
    lead_id: number;
    sequence: number;
    scheduled_at: string;
    address: string | null;
    maps_url: string | null;
    is_outside_pekanbaru: boolean;
    quotation_id: number | null;
    status: LeadSurveyStatus;
    result_note: string | null;
    cancel_reason: string | null;
    creator?: Pick<User, 'id' | 'name'> | null;
    created_at: string;
}

/** PRD 4.1 "Pipeline History Log" — one status change, flattened by LeadController::show(). */
export interface PipelineLogEntry {
    id: number;
    /** Null on the "Lead dibuat." entry written at creation. */
    from_status: LeadStatus | null;
    to_status: LeadStatus;
    note: string | null;
    changed_by_name: string | null;
    created_at: string;
}

/** PRD 4.2 — Desain */
export type DesignStatus =
    | 'BRIEF'
    | 'DESAIN'
    | 'WAITING_ACC_DESAIN'
    | 'REVISI_DESAIN'
    | 'ACC_DESAIN'
    | 'GAMBAR_RAB'
    | 'PEMBUATAN_PENAWARAN'
    | 'WAITING_ACC_PENAWARAN'
    | 'PRODUKSI'
    | 'DONE_PRODUKSI'
    | 'REJECT_PRODUKSI'
    | 'HOLD_CLIENT'
    | 'REVISI_CLIENT';

/** Shared between Design.jenis_project and future Project.jenis_project — see App\Enums\ProjectType. */
export type ProjectType =
    | 'TOKO'
    | 'CAFE'
    | 'RENOVASI'
    | 'KAMAR_SET'
    | 'KITCHEN_SET'
    | 'KANTOR'
    | 'ARSITEKTURAL'
    | 'RUANG_TAMU_TV'
    | 'RETAIL_TOKO'
    | 'LAINNYA';

/** PRD 4.2 "PIC & Sub-Staff" — a `design_staff` pivot row, loaded via `with('staff:id,name')`. */
export type DesignStaffMember = Pick<User, 'id' | 'name'> & {
    pivot: { role_note: string | null };
};

export interface Design {
    id: number;
    lead_id: number;
    pic_id: number | null;
    pic?: Pick<User, 'id' | 'name'>;
    staff?: DesignStaffMember[];
    jenis_project: ProjectType | null;
    status: DesignStatus;
    target_hari: number | null;
    start_date: string | null;
    deadline: string | null;
    delay_hari: number;
    design_urls: string[] | null;
    brief_note: string | null;
    problem: string | null;
    client_acc: boolean;
    acc_date: string | null;
    created_at: string;
    updated_at: string;
}

/** PRD 4.3 — Quotation / RAB */
/** Sprint 12 #6 — App\Enums\QuotationType. */
export type QuotationType = 'SURVEY' | 'DESAIN' | 'PROYEK';

/** Sprint 12 #12 — App\Enums\PaymentTermTrigger. */
export type PaymentTermTrigger = 'DI_MUKA' | 'TANGGAL' | 'MILESTONE' | 'PROYEK_SELESAI';

export type QuotationStatus =
    /** Sprint 12 #7 — asked for by Marketing, not picked up by the Estimator yet. */
    | 'DIMINTA'
    | 'DRAFT'
    /** Sprint 12 #7 — waiting for the PM / Asisten PM item review. */
    | 'SUBMITTED'
    /** RAB Proyek only — PM approved, waiting for the CEO. */
    | 'WAITING_CEO'
    | 'APPROVED_INTERNAL'
    /** The Estimator handed the final RAB to Marketing. */
    | 'READY_TO_SEND'
    | 'SENT_TO_CLIENT'
    | 'CLIENT_APPROVED'
    | 'CANCELLED'
    /** Pre-Sprint-12 states — no longer persisted (App\Enums\QuotationStatus). */
    | 'CEO_REVIEW'
    | 'PM_REVIEW'
    | 'APPROVED'
    | 'REJECTED';

/** Sprint 12 #20–#21 — App\Enums\InvoiceType / InvoiceStatus. */
export type InvoiceType = 'JASA_SURVEY' | 'JASA_DESAIN' | 'DP' | 'TERMIN' | 'PELUNASAN' | 'TAMBAHAN';
export type InvoiceStatus = 'DITERBITKAN' | 'MENUNGGU_VERIFIKASI' | 'TERVERIFIKASI';

export interface Invoice {
    id: number;
    number: string;
    lead_id: number;
    lead?: Pick<Lead, 'id' | 'client_name'>;
    project_id: number | null;
    quotation_id: number | null;
    quotation?: Pick<Quotation, 'id' | 'type' | 'version'> | null;
    termin_id: number | null;
    type: InvoiceType;
    amount: string;
    due_date: string;
    status: InvoiceStatus;
    issued_by: number;
    issuer?: Pick<User, 'id' | 'name'>;
    issued_at: string;
    payment_proof_url: string | null;
    proof_submitted_at: string | null;
    bank_account_id: number | null;
    bank_account?: Pick<BankAccount, 'id' | 'label'> | null;
    paid_date: string | null;
    verified_by: number | null;
    verifier?: Pick<User, 'id' | 'name'> | null;
    verified_at: string | null;
    reject_reason: string | null;
    rejected_at: string | null;
}

/** Sprint 12 #8 — one ✔/✘ mark on a RAB item (App\Models\QuotationItemReview). */
export interface QuotationItemReview {
    id: number;
    quotation_id: number;
    version: number;
    quotation_item_id: number | null;
    item_description: string;
    section_name: string | null;
    stage: 'PM' | 'CEO';
    reviewer_id: number;
    reviewer?: Pick<User, 'id' | 'name'>;
    verdict: 'OK' | 'SALAH';
    note: string | null;
    created_at: string;
}

export interface QuotationItem {
    id: number;
    quotation_id: number;
    /** Sprint 12 #11 — null = pre-Sprint-12 item, shown under "Umum". */
    section_id: number | null;
    description: string;
    /** Optional dimensions (P, T/L) — informational, the volume is typed. */
    dim_length: number | null;
    dim_width_height: number | null;
    /** Fractional allowed (Sprint 11) — 2.5. */
    qty: number;
    unit_id: number;
    unit: UnitOption | null;
    unit_price: string;
    total_price: string;
    sort_order: number;
}

/** Sprint 12 #11 — a bagian pekerjaan of the RAB. */
export interface QuotationSection {
    id: number;
    quotation_id: number;
    name: string;
    sort_order: number;
}

/** Sprint 12 #12 — one DP/termin row; `amount` is derived from the total server-side. */
export interface QuotationPaymentTerm {
    id: number;
    quotation_id: number;
    sequence: number;
    label: string;
    percentage: string;
    amount: string;
    trigger: PaymentTermTrigger;
    due_date: string | null;
    milestone_name: string | null;
}

export interface QuotationApproval {
    id: number;
    quotation_id: number;
    /** The quotation version this decision was about. */
    version: number;
    approver_id: number;
    approver?: Pick<User, 'id' | 'name'>;
    /** CLIENT = the client's decision, recorded by the CEO/Marketing `approver`. */
    approver_role: 'CEO' | 'PM' | 'CLIENT';
    status: 'APPROVED' | 'REJECTED';
    note: string | null;
    created_at: string;
}

/** Why a quotation version was closed — App\Models\QuotationRevision::REASON_*. */
export type QuotationRevisionReason = 'CEO_REJECTED' | 'PM_REJECTED' | 'CLIENT_REJECTED';

/** One RAB line as frozen in a revision snapshot (money stays a decimal string, like QuotationItem). */
export interface QuotationRevisionItem {
    /** Section name — snapshots taken since Sprint 12. */
    section?: string | null;
    description: string;
    dim_length?: number | null;
    dim_width_height?: number | null;
    qty: number;
    /** Master Satuan code — snapshots taken since Sprint 11. */
    unit_code?: string | null;
    /** Free-text unit — snapshots taken before Sprint 11 (history is never rewritten). */
    unit?: string;
    unit_price: string;
    total_price: string;
}

/** PRD 4.3 "Versi Revisi" — a closed (rejected) version, append-only. */
export interface QuotationRevision {
    id: number;
    quotation_id: number;
    version: number;
    total_amount: string;
    items: QuotationRevisionItem[];
    /** Sprint 12 — totals and payment scheme of the closed version (null on older snapshots). */
    details: {
        items_total: string | null;
        discount_amount: string | null;
        rounded_total: string | null;
        payment_terms: Pick<QuotationPaymentTerm, 'sequence' | 'label' | 'percentage' | 'amount' | 'trigger' | 'due_date' | 'milestone_name'>[];
    } | null;
    reason: QuotationRevisionReason;
    note: string | null;
    closed_by: number;
    closer?: Pick<User, 'id' | 'name'> | null;
    created_at: string;
}

export interface Quotation {
    id: number;
    lead_id: number;
    lead?: Pick<Lead, 'id' | 'client_name'>;
    type: QuotationType;
    lead_survey_id: number | null;
    parent_quotation_id: number | null;
    /** Σ item subtotals. */
    items_total: string | null;
    discount_amount: string;
    /** Pembulatan typed by the Estimator; null = total − discount. */
    rounded_total: string | null;
    /** What the client pays: rounded_total ?? items_total − discount_amount. */
    total_amount: string;
    status: QuotationStatus;
    requested_by: number | null;
    requester?: Pick<User, 'id' | 'name'> | null;
    request_note: string | null;
    /** Set when Marketing sends the offer (+14 days), cleared when it returns to DRAFT. */
    valid_until: string | null;
    /** Sprint 12 — Marketing's first / latest "Kirim ke Client". */
    first_sent_at: string | null;
    sent_at: string | null;
    /** Sprint 12 #13 — the client's approval on the public link. */
    client_approved_at: string | null;
    client_approved_ip: string | null;
    client_approved_user_agent: string | null;
    version: number;
    created_by: number;
    items?: QuotationItem[];
    sections?: QuotationSection[];
    payment_terms?: QuotationPaymentTerm[];
    approvals?: QuotationApproval[];
    revisions?: QuotationRevision[];
    created_at: string;
    updated_at: string;
}

/** PRD 4.4 — Project Management */
export type ProjectStatus = 'ACTIVE' | 'COMPLETED' | 'ON_HOLD' | 'CANCELLED';
export type MilestoneStatus =
    | 'PENDING'
    | 'IN_PROGRESS'
    | 'QA_WAITING'
    | 'COMPLETED'
    | 'OVERDUE';

export interface Project {
    id: number;
    lead_id: number;
    /** Sprint 12 #19 — the RAB Fix (null on pre-Sprint-12 projects). */
    quotation_id?: number | null;
    /** Sprint 12 D2. */
    assistant_pm_id?: number | null;
    assistant_pm?: Pick<User, 'id' | 'name'> | null;
    name: string;
    pm_id: number;
    /** Loaded via `with('pm:id,name')` — see Project::pm() for why it isn't named `projectManager`. */
    pm?: Pick<User, 'id' | 'name'>;
    lead?: Pick<Lead, 'id' | 'client_name'>;
    status: ProjectStatus;
    start_date: string | null;
    end_date: string | null;
    contract_value: string;
    created_at: string;
    updated_at: string;
}

/** ProjectService::statusNote() — why the project is ON_HOLD/CANCELLED (from its audit row). */
export interface ProjectStatusNote {
    note: string | null;
    by: string | null;
    at: string;
}

export interface Milestone {
    id: number;
    project_id: number;
    project?: Pick<Project, 'id' | 'name'>;
    name: string;
    target_date: string;
    status: MilestoneStatus;
    order: number;
    created_at: string;
    updated_at: string;
}

/** PRD 4.5 — Task Management (Field Staff) */
export type TaskStatus = 'PENDING' | 'ONPROGRESS' | 'PENGECEKAN' | 'DONE' | 'OVER';
export type TaskPriority = 'HIGH' | 'MEDIUM' | 'LOW';
/** `tasks.index?due=` — Task::scopeByDue(). */
export type TaskDueFilter = 'today' | 'week' | 'overdue';

export interface Task {
    id: number;
    project_id: number;
    /** `status` is loaded on the task list (hides edit actions on closed projects). */
    project?: Pick<Project, 'id' | 'name'> & Partial<Pick<Project, 'status'>>;
    milestone_id: number | null;
    milestone?: Pick<Milestone, 'id' | 'name'>;
    title: string;
    description: string | null;
    assignee_id: number | null;
    assignee?: Pick<User, 'id' | 'name'>;
    created_by: number;
    due_date: string | null;
    status: TaskStatus;
    /** Status before TaskOverdueJob set OVER — restored when the PM moves the deadline back. */
    pre_overdue_status?: TaskStatus | null;
    priority: TaskPriority;
    is_locked: boolean;
    kendala: string | null;
    note: string | null;
    rate_per_task: string | null;
    completed_at: string | null;
    /** `withExists('wagePayment')` — PM views only; a paid DONE task is fully locked. */
    is_wage_paid?: boolean;
    created_at: string;
    updated_at: string;
}

export interface DailyTaskForm {
    id: number;
    task_id: number;
    task?: Pick<Task, 'id' | 'title' | 'project_id'> & { project?: Pick<Project, 'id' | 'name'> };
    staff_id: number;
    staff?: Pick<User, 'id' | 'name'>;
    work_date: string;
    status_update: TaskStatus;
    kendala: string | null;
    notes: string | null;
    submitted_at: string;
}

/** PRD 4.4 — Progress Log */
export interface ProgressLog {
    id: number;
    project_id: number;
    logged_by: number;
    logger?: Pick<User, 'id' | 'name'>;
    percentage: number;
    description: string;
    ref_urls: string[] | null;
    log_date: string;
    created_at: string;
}

/** PRD 4.6 — QA */
export type QAStatus = 'PENDING' | 'APPROVED' | 'REJECTED';

export interface ChecklistItem {
    label: string;
    passed: boolean;
    note: string | null;
}

export interface QaForm {
    id: number;
    project_id: number;
    project?: Pick<Project, 'id' | 'name'>;
    milestone_id: number;
    milestone?: Pick<Milestone, 'id' | 'name' | 'order' | 'status'>;
    reviewer_id: number | null;
    reviewer?: Pick<User, 'id' | 'name'>;
    status: QAStatus;
    checklist_data: ChecklistItem[];
    rejection_count: number;
    notes: string | null;
    reviewed_at: string | null;
    created_at: string;
}

/** PRD 4.5 / Overtime schema — Pengajuan Lembur */
export type OvertimeStatus =
    | 'PENDING'
    | 'PENDING_FINANCE'
    | 'APPROVED_FINANCE'
    | 'REJECTED';

export interface OvertimeRequest {
    id: number;
    staff_id: number;
    staff?: Pick<User, 'id' | 'name'>;
    project_id: number;
    project?: Pick<Project, 'id' | 'name'>;
    task_id: number | null;
    hours: string;
    rate_per_hour: string;
    total_amount: string;
    work_date: string;
    reason: string;
    reject_note: string | null;
    status: OvertimeStatus;
    pm_approved_by: number | null;
    pm_approved_at: string | null;
    finance_approved_by: number | null;
    finance_approved_at: string | null;
    created_at: string;
    updated_at: string;
}

/**
 * Sprint 9 decision #10 — a penalty is paid by the tukang manually;
 * derived from `is_deducted` (Penalty::STATUS_UNPAID / STATUS_PAID), also
 * the Penalty page's `status` filter values.
 */
export type PenaltyPaymentStatus = 'BELUM_DIBAYAR' | 'LUNAS';

/** PRD 6.5 — Logika Penalti Harian */
export interface Penalty {
    id: number;
    staff_id: number;
    staff?: Pick<User, 'id' | 'name'>;
    type: string;
    reference_id: number | null;
    amount: string;
    date_occurred: string;
    /** true = lunas (paid by the tukang, recorded by Finance) — wages are never deducted. */
    is_deducted: boolean;
    collected_at: string | null;
    collected_by: number | null;
    collector?: Pick<User, 'id' | 'name'> | null;
    /** The PEMASUKAN PENALTY_COLLECT transaction that paid it. */
    finance_transaction_id: number | null;
    /** Not sent to Field Staff (finance data, PRD §7.1). */
    finance_transaction?: (Pick<FinanceTransaction, 'id' | 'bank_account_id' | 'date'> & { bank_account?: Pick<BankAccount, 'id' | 'label'> | null }) | null;
    created_at: string;
}

/** PRD 4.7 — Dana Family Gathering */
export interface FamilyGatheringFund {
    id: number;
    type: 'INCOME' | 'EXPENSE';
    amount: string;
    description: string | null;
    source_penalty_id: number | null;
    source_penalty?: (Pick<Penalty, 'id' | 'staff_id' | 'is_deducted' | 'collected_at'> & { staff?: Pick<User, 'id' | 'name'> }) | null;
    /** EXPENSE rows: the PENGELUARAN the usage was paid out of (null for INCOME and legacy rows). */
    finance_transaction_id: number | null;
    finance_transaction?: (Pick<FinanceTransaction, 'id' | 'bank_account_id' | 'date'> & { bank_account?: Pick<BankAccount, 'id' | 'label'> | null }) | null;
    recorded_by: number;
    recorder?: Pick<User, 'id' | 'name'>;
    created_at: string;
}

/** FamilyGatheringFundService::summary() — spendable = collected + otherIncome − totalExpense. */
export interface FamilyFundSummary {
    /** Income from every issued penalty (paid or not). */
    penaltyTotal: number;
    /** …of which the penalty is already paid. */
    collected: number;
    outstanding: number;
    /** Income not tied to a penalty. */
    otherIncome: number;
    totalExpense: number;
    spendable: number;
}

/** PRD 4.4/4.7/6.4 — Termin (Finance – Termin) */
export type TerminStatus = 'SCHEDULED' | 'INVOICED' | 'PAID' | 'OVERDUE';

export interface Termin {
    id: number;
    project_id: number;
    project?: Pick<Project, 'id' | 'name'>;
    milestone_id: number | null;
    milestone?: Pick<Milestone, 'id' | 'name'>;
    /** Sprint 12 #12 — copied from the approved payment scheme (null on pre-Sprint-12 termins). */
    payment_term_id?: number | null;
    trigger?: PaymentTermTrigger | null;
    milestone_name?: string | null;
    /** Sprint 12 #20 — the invoice Marketing issued for it. */
    invoice_id?: number | null;
    invoice?: Pick<Invoice, 'id' | 'number' | 'status'> | null;
    termin_number: number;
    /** DECIMAL(5,2) since Sprint 12 — a scheme row may be 33,33 %. */
    percentage: string | number;
    amount: string;
    /** daiku_schema.sql — partial payments; sisa_piutang = amount - dp_amount - pelunasan (DB-generated). */
    dp_amount: string;
    pelunasan: string;
    sisa_piutang: string;
    /** Null for a milestone- / completion-triggered scheme termin until the work gets there. */
    scheduled_date: string | null;
    status: TerminStatus;
    bank_account_id: number | null;
    bank_account?: Pick<BankAccount, 'id' | 'label'>;
    invoice_url: string | null;
    paid_at: string | null;
    created_at: string;
    updated_at: string;
}

/** PRD 4.7 — Finance Transaction */
export type FinanceTransactionType = 'PEMASUKAN' | 'PENGELUARAN';

export type FinanceCategory =
    | 'DOWN_PAYMENT'
    | 'TERMIN'
    | 'OPERASIONAL'
    | 'PINJAMAN'
    | 'BELI_BAHAN'
    | 'ANGSURAN'
    | 'GAJI_KARYAWAN'
    | 'LEMBUR_BONUS'
    | 'LOGISTIK'
    | 'HUTANG_IDEAL'
    | 'PEGANGAN'
    | 'JASA_DESAIN'
    | 'VENDOR'
    | 'PINDAH_DANA'
    | 'KONSUMSI'
    | 'CONSUMABLE'
    | 'PERALATAN_ASET'
    | 'BBM'
    | 'OWNER'
    | 'PENALTY_COLLECT'
    /** Sprint 12 Sub 6 — verified Jasa Survey / Jasa Desain invoices. */
    | 'PENDAPATAN_SURVEY'
    | 'PENDAPATAN_DESAIN'
    | 'LAINNYA';

export interface FinanceTransaction {
    id: number;
    project_id: number | null;
    project?: Pick<Project, 'id' | 'name'> | null;
    bank_account_id: number | null;
    bank_account?: Pick<BankAccount, 'id' | 'label'> | null;
    type: FinanceTransactionType;
    kategori: FinanceCategory | null;
    amount: string;
    description: string | null;
    reference_id: number | null;
    date: string;
    created_by: number;
    creator?: Pick<User, 'id' | 'name'>;
    attachments: string[] | null;
    created_at: string;
    updated_at: string;
}

/** PRD 4.8 — Logistik */
export interface Material {
    id: number;
    name: string;
    unit_id: number;
    /** Always eager-loaded (Material::$with) — Master Satuan. */
    unit: UnitOption | null;
    /** Sprint 11 §5.5 — structured identity; `name` is the generated display name. */
    material_category_id: number;
    category?: Pick<MaterialCategory, 'id' | 'name' | 'code_prefix'> | null;
    /** Generated per category, e.g. KYP-0012. */
    code: string;
    base_name: string | null;
    spec: string | null;
    brand: string | null;
    /** Flagged by the anti-duplicate rebuild — waits on the Cek Duplikat page. */
    possible_duplicate: boolean;
    /** False once merged into another item (Lapis 6). */
    is_active: boolean;
    merged_into_id: number | null;
    /** "Harga gudang" set by Logistics — what a project is charged per unit taken from stock (Sprint 11 #5). */
    cost_price: string;
    sell_price: string;
    /** Fractional allowed (Sprint 11) — 2.5. */
    stock: number;
    min_stock: number;
    /** Appended accessors (App\Models\Material) — sell_price − cost_price. */
    margin: number;
    margin_percent: number | null;
    is_low_stock: boolean;
    created_at: string;
    updated_at: string;
}

/** IN = receipt, OUT = issued to a project, RETURN = leftover returned from a project (Sprint 11). */
export type StockMovementType = 'IN' | 'OUT' | 'RETURN' | 'MERGE_OUT' | 'MERGE_IN';

export interface StockMovement {
    id: number;
    material_id: number;
    material?: Pick<Material, 'id' | 'name' | 'unit_id' | 'unit'>;
    /** OUT: the receiving project. RETURN: the project the leftover came from. */
    project_id: number | null;
    project?: Pick<Project, 'id' | 'name'> | null;
    project_material_id: number | null;
    type: StockMovementType;
    qty: number;
    stock_after: number;
    /** Price per unit at the time of the movement (OUT: harga gudang charged to the project). */
    unit_cost: string | null;
    movement_date: string;
    note: string | null;
    recorded_by: number;
    recorder?: Pick<User, 'id' | 'name'>;
    created_at: string;
}

/** Sprint 11 Sub 3 — where a project material line comes from. */
export type ProjectMaterialSource = 'GUDANG' | 'PEMBELIAN' | 'CUSTOM';

/** Sprint 11 Sub 4 — out-of-catalog requests; catalog lines are DISETUJUI from the start. */
export type MaterialRequestStatus = 'MENUNGGU_PM' | 'DIAJUKAN' | 'DISETUJUI' | 'DITOLAK';

/** Sub 4 — Logistics' four decisions on a request (decision #13). */
export type MaterialRequestDecision = 'PAKAI_KATALOG' | 'DAFTAR_KATALOG' | 'CUSTOM' | 'TOLAK';

/** What the requester originally asked for (`requested_snapshot`) — Logistics may change it when approving. */
export interface MaterialRequestSnapshot {
    name: string;
    spec?: string;
    unit_id?: number;
    qty: number;
    estimated_price?: number;
    reason?: string;
    vendor_id?: number;
    photo_link?: string;
}

export interface ProjectMaterial {
    id: number;
    project_id: number;
    source: ProjectMaterialSource;
    /** Null only for CUSTOM lines. */
    material_id: number | null;
    material?: Pick<Material, 'id' | 'name' | 'unit_id' | 'unit' | 'stock' | 'cost_price'> | null;
    custom_name: string | null;
    custom_spec: string | null;
    /** Null only while a Tukang request waits for Logistics to pick the unit. */
    unit_id: number | null;
    unit: UnitOption | null;
    /** Last actual purchase price per unit (PEMBELIAN/CUSTOM). */
    unit_price: string | null;
    vendor_id: number | null;
    vendor?: Pick<Vendor, 'id' | 'name'> | null;
    request_status: MaterialRequestStatus;
    /** Sub 4 — TIM (PM/Estimator) or TUKANG (PM approves first); null for lines planned from the catalog. */
    request_channel: 'TIM' | 'TUKANG' | null;
    request_reason: string | null;
    photo_link: string | null;
    requested_by: number | null;
    requester?: Pick<User, 'id' | 'name'> | null;
    requested_snapshot: MaterialRequestSnapshot | null;
    submitted_at: string | null;
    review_decision: MaterialRequestDecision | null;
    reject_reason: string | null;
    reviewed_at: string | null;
    qty_planned: number;
    qty_received: number;
    qty_used: number;
    qty_returned: number;
    qty_wasted: number;
    qty_handed_over: number;
    /** What the project is charged — Σ issued × harga gudang, Σ bought × harga beli. Returns never lower it (Sprint 11 #9). */
    cost_total: string;
    waste_reason: string | null;
    handover_note: string | null;
    /** Appended — catalog name or custom name. */
    display_name: string;
    /** Appended — received − used − returned − wasted − handed over. */
    leftover: number;
    created_at: string;
    updated_at: string;
}

export type AssetCondition = 'GOOD' | 'FAIR' | 'DAMAGED';

export interface Asset {
    id: number;
    name: string;
    category: string | null;
    purchase_date: string | null;
    value: string | null;
    condition: AssetCondition;
    location: string | null;
    notes: string | null;
    /** PRD 4.7 "Aset & Cicilan" — plan set by Logistics; `paid_install` mirrors the payment ledger. */
    has_installment: boolean;
    total_install: string | null;
    paid_install: string;
    /** Expected amount per payment (Sprint 9 deviation) — prefills Finance's payment dialog. */
    installment_amount: string | null;
    /** Day of month (1–28) the installment is due. */
    installment_due_day: number | null;
    /** Appended: total_install − paid_install, never negative; null without a plan. */
    remaining_install: string | null;
    created_at: string;
    updated_at: string;
}

/** Derived by Asset::installmentStatus() (not a DB column) — StatusChip already maps the keys. */
export type AssetInstallmentStatus = 'BERJALAN' | 'JATUH_TEMPO' | 'LUNAS';

/** An asset with a plan as listed on the Cicilan Aset pages. */
export interface InstallmentAsset extends Asset {
    total_install: string;
    installment_status: AssetInstallmentStatus;
    /** Loaded on the list only (withPaidThisMonth / withMax). */
    paid_this_month?: boolean | number;
    last_paid_at?: string | null;
    installment_payments?: AssetInstallmentPayment[];
}

/** One installment payment — append-only ledger with its ANGSURAN FinanceTransaction. */
export interface AssetInstallmentPayment {
    id: number;
    asset_id: number;
    amount: string;
    paid_at: string;
    bank_account_id: number;
    bank_account?: Pick<BankAccount, 'id' | 'label'> | null;
    finance_transaction_id: number;
    note: string | null;
    created_by: number;
    creator?: Pick<User, 'id' | 'name'>;
    created_at: string;
}

/** PRD 9.4 — Audit Trail (append-only) */
export interface AuditLog {
    id: number;
    user_id: number | null;
    user?: Pick<User, 'id' | 'name'> | null;
    action: string;
    model_type: string;
    model_id: number;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    ip_address: string | null;
    created_at: string;
}

/** PRD 5.1 — Notifications */
export interface AppNotification {
    id: number;
    user_id: number;
    type: string;
    title: string;
    message: string;
    is_read: boolean;
    metadata: Record<string, unknown> | null;
    created_at: string;
}

/**
 * Master Data (SuperAdmin-only, added on request — not in the original
 * PRD). Reference tables other modules will point to by ID; see
 * app/Http/Controllers/MasterData. Named `*Option`/`Branch`/`BankAccount`
 * rather than reusing `LeadCategory` (already a status-style union above)
 * to avoid a name collision.
 */
export interface Branch {
    id: number;
    name: string;
    code: string;
    address: string | null;
    created_at: string;
    updated_at: string;
}

export interface LeadSourceOption {
    id: number;
    name: string;
    created_at: string;
    updated_at: string;
}

export interface LeadCategoryOption {
    id: number;
    name: string;
    created_at: string;
    updated_at: string;
}

/** Sprint 11 Sub 1 — Master Satuan (App\Models\Unit). No conversion between units. */
export interface Unit {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
    sort_order: number;
}

/** What a form's `units` prop and an eager-loaded `unit` relation carry. */
export type UnitOption = Pick<Unit, 'id' | 'code' | 'name'>;

/** Data Master row — `in_use` units can only be deactivated. */
export interface UnitRow extends Unit {
    in_use: boolean;
}

/** Sprint 11 Sub 5 — Data Master → Kategori Material (AppModelsMaterialCategory). */
export interface MaterialCategory {
    id: number;
    name: string;
    /** Starts every item code of the category (KYP-0012). */
    code_prefix: string;
    is_active: boolean;
    sort_order: number;
    materials_count?: number;
}

/** Sprint 11 Sub 5 — "plywood" means "triplek" when matching catalog items. */
export interface MaterialSynonym {
    id: number;
    term: string;
    canonical: string;
}

/** Sprint 11 Sub 2 — Master Vendor (App\Models\Vendor). */
export type VendorType = 'MATERIAL' | 'JASA';

export interface Vendor {
    id: number;
    name: string;
    contact: string | null;
    address: string | null;
    type: VendorType;
    bank_name: string | null;
    bank_account_number: string | null;
    account_holder: string | null;
    is_active: boolean;
    created_at: string;
    updated_at: string;
}

/** What a VendorSelect's `vendors` prop carries (active vendors only). */
export type VendorOption = Pick<Vendor, 'id' | 'name' | 'type'>;

export interface BankAccount {
    id: number;
    bank_name: string;
    account_no: string;
    label: string;
    /** Saldo awal — before the first transaction recorded in the system. The running balance is derived, never stored (Sprint 9). */
    opening_balance: string;
    is_active: boolean;
    /** Only when loaded through BankAccount::withBalance() — all-time Σ PEMASUKAN / Σ PENGELUARAN (Pindah Dana included). */
    total_income?: string | null;
    total_expense?: string | null;
    /** Appended accessor (BankAccount::currentBalance()) — opening_balance + total_income − total_expense. */
    current_balance?: number;
    created_at: string;
    updated_at: string;
}

/** FinanceTransactionService::accountSummary() — one row of the Finance Dashboard's per-account cash flow. */
export interface BankAccountSummaryRow {
    id: number;
    label: string;
    bank_name: string;
    account_no: string;
    is_active: boolean;
    opening_balance: number;
    total_income: number;
    total_expense: number;
    current_balance: number;
    month_income: number;
    month_expense: number;
}

export interface BankAccountSummary {
    /** 'yyyy-MM' */
    month: string;
    label: string;
    accounts: BankAccountSummaryRow[];
    /** "Keseluruhan" — balances summed, masuk/keluar company-level (Pindah Dana left out). */
    total: Omit<BankAccountSummaryRow, 'id' | 'label' | 'bank_name' | 'account_no' | 'is_active'>;
}

/** PRD 4.7 — Pinjaman Tukang (staff_loans). `remaining` is DB-generated (amount - paid_amount). */
export interface StaffLoan {
    id: number;
    staff_id: number;
    staff?: Pick<User, 'id' | 'name'>;
    amount: string;
    paid_amount: string;
    remaining: string;
    /** Deducted from each wage payment, capped at `remaining` and the wage itself (Sprint 8 decision #2). */
    installment_amount: string;
    description: string | null;
    bank_account_id: number | null;
    bank_account?: Pick<BankAccount, 'id' | 'label'> | null;
    created_by: number;
    creator?: Pick<User, 'id' | 'name'>;
    payments?: StaffLoanPayment[];
    created_at: string;
    updated_at: string;
}

export interface StaffLoanPayment {
    id: number;
    staff_loan_id: number;
    amount: string;
    paid_date: string;
    note: string | null;
    /** Set when the installment was deducted from a wage payment (FinanceTransactionService::payStaffForTask). */
    task_id: number | null;
    created_by: number;
    creator?: Pick<User, 'id' | 'name'>;
    created_at: string;
}

/** PRD 4.7 — Hutang Supplier (supplier_debts). `remaining` is DB-generated. */
export interface SupplierDebt {
    id: number;
    vendor_id: number;
    /** Master Vendor (Sprint 11 Sub 2) — replaced the free-text supplier name. */
    vendor?: Pick<Vendor, 'id' | 'name'> & Partial<Pick<Vendor, 'contact' | 'bank_name' | 'bank_account_number' | 'account_holder'>>;
    total_amount: string;
    paid_amount: string;
    remaining: string;
    project_id: number | null;
    project?: Pick<Project, 'id' | 'name'> | null;
    description: string | null;
    due_date: string | null;
    /** Appended by SupplierDebt::status() — derived from remaining/due_date, not a DB column. */
    status?: 'BERJALAN' | 'JATUH_TEMPO' | 'LUNAS';
    created_by: number;
    creator?: Pick<User, 'id' | 'name'>;
    payments?: SupplierDebtPayment[];
    created_at: string;
    updated_at: string;
}

export interface SupplierDebtPayment {
    id: number;
    supplier_debt_id: number;
    amount: string;
    paid_date: string;
    bank_account_id: number;
    bank_account?: Pick<BankAccount, 'id' | 'label'> | null;
    note: string | null;
    created_by: number;
    creator?: Pick<User, 'id' | 'name'>;
    created_at: string;
}

/** PRD 4.7 — Alokasi Persentase (finance_allocation_configs), editable by CEO/Finance. */
export interface FinanceAllocationConfig {
    id: number;
    label: string;
    percentage: string;
    kategori: FinanceCategory;
    is_active: boolean;
    created_at: string;
    updated_at: string;
}

/** One row of FinanceAllocationService::breakdownFor(Project). */
export interface FinanceAllocationLine {
    label: string;
    kategori: FinanceCategory;
    percentage: number;
    amount: number;
}

/**
 * PRD 4.7 "Gaji Karyawan Tetap" — permanent staff (not field staff); `user_id` optionally links an account.
 * Since Sprint 10 the job title is `position_id` → Divisi → Jabatan (never free text), managed by HR.
 */
export interface Employee {
    id: number;
    name: string;
    position_id: number;
    position?: (Pick<Position, 'id' | 'name' | 'division_id'> & { division?: Pick<Division, 'id' | 'name'> }) | null;
    user_id: number | null;
    user?: Pick<User, 'id' | 'name'> | null;
    base_salary: string;
    bank_name: string | null;
    account_no: string | null;
    join_date: string | null;
    is_active: boolean;
    notes: string | null;
    created_by: number;
    created_at: string;
    updated_at: string;
}

/** One monthly salary (append-only); amount = base_salary (snapshot) + allowance − deduction. */
export interface SalaryPayment {
    id: number;
    employee_id: number;
    /** `YYYY-MM` */
    period: string;
    base_salary: string;
    allowance: string;
    deduction: string;
    amount: string;
    bank_account_id: number;
    bank_account?: Pick<BankAccount, 'id' | 'label'> | null;
    paid_at: string;
    note: string | null;
    finance_transaction_id: number;
    created_by: number;
    creator?: Pick<User, 'id' | 'name'>;
    created_at: string;
}

/** One row of the Penggajian table (PayrollController::index()). */
export interface PayrollRow {
    employee: Pick<Employee, 'id' | 'name' | 'base_salary' | 'bank_name' | 'account_no' | 'is_active'> & {
        position_name: string | null;
    };
    payment: SalaryPayment | null;
}

// ── SDM / HR (Sprint 10, .claude/plan/sprint-10-sdm.md) ─────────────────

/** Decision #10 — top level of the Divisi → Jabatan master. */
export interface Division {
    id: number;
    name: string;
    is_active: boolean;
    sort_order: number;
    positions?: Position[];
    positions_count?: number;
}

/** Decision #10 — a job position under one division (unique name per division). */
export interface Position {
    id: number;
    division_id: number;
    division?: Pick<Division, 'id' | 'name'>;
    name: string;
    is_active: boolean;
    sort_order: number;
    /** HR-eligible employees holding it (structure page). */
    employees_count?: number;
}

/** §3.1 — SP1→SP2→SP3 escalate (decision #12); PEMBATALAN cancels a record via `voids_id`. */
export type DisciplinaryType = 'TEGURAN_LISAN' | 'SP1' | 'SP2' | 'SP3' | 'CATATAN' | 'PEMBATALAN';

/** §3.1 — append-only reprimand / warning letter. */
export interface DisciplinaryRecord {
    id: number;
    employee_id: number;
    employee?: Pick<Employee, 'id' | 'name'> & { position?: Employee['position'] };
    type: DisciplinaryType;
    issued_on: string;
    valid_until: string | null;
    description: string;
    link: string | null;
    voids_id: number | null;
    recorded_by: number;
    recorder?: Pick<User, 'id' | 'name'>;
    created_at: string;
    /** Derived: cancelled by a PEMBATALAN row. */
    is_voided?: boolean;
    /** Derived: an SP in force today. */
    is_active_sp?: boolean;
}

/** Decision #3 — HR requests, the CEO decides once. */
export type SalaryChangeStatus = 'PENDING' | 'APPROVED' | 'REJECTED';

export interface SalaryChange {
    id: number;
    employee_id: number;
    employee?: Pick<Employee, 'id' | 'name'> & { position?: Employee['position'] };
    old_salary: string;
    new_salary: string;
    effective_date: string;
    reason: string;
    status: SalaryChangeStatus;
    reject_note: string | null;
    performance_review_id: number | null;
    requested_by: number;
    requester?: Pick<User, 'id' | 'name'>;
    decided_by: number | null;
    decider?: Pick<User, 'id' | 'name'> | null;
    decided_at: string | null;
    created_at: string;
}

/** §3.3 */
export type KpiIndicatorSource = 'AUTO' | 'MANUAL';
export type KpiDirection = 'HIGHER_BETTER' | 'LOWER_BETTER';
export type KpiPeriodStatus = 'OPEN' | 'CLOSED';

export interface KpiIndicator {
    id: number;
    kpi_template_id: number;
    name: string;
    source: KpiIndicatorSource;
    metric_key: string | null;
    target: string;
    /** Percent; a template's weights total 100. */
    weight: string;
    direction: KpiDirection;
    sort_order: number;
}

/** One template per position. */
export interface KpiTemplate {
    id: number;
    position_id: number;
    position?: Employee['position'];
    is_active: boolean;
    indicators?: KpiIndicator[];
}

export interface KpiPeriod {
    id: number;
    /** `YYYY-MM` */
    period: string;
    status: KpiPeriodStatus;
    closed_by: number | null;
    closer?: Pick<User, 'id' | 'name'> | null;
    closed_at: string | null;
}

/** One employee × indicator × month, indicator snapshotted in; locked once the period is CLOSED. */
export interface KpiScore {
    id: number;
    kpi_period_id: number;
    employee_id: number;
    kpi_indicator_id: number | null;
    indicator_name: string;
    source: KpiIndicatorSource;
    metric_key: string | null;
    target: string;
    weight: string;
    direction: KpiDirection;
    actual: string | null;
    /** 0–120 */
    score: string | null;
    weighted_score: string | null;
    input_by: number | null;
}

/** §3.4 — DRAFT → SUBMITTED → APPROVED → ACKNOWLEDGED (CEO may return SUBMITTED to DRAFT). */
export type ReviewStatus = 'DRAFT' | 'SUBMITTED' | 'APPROVED' | 'ACKNOWLEDGED';
export type ReviewGrade = 'A' | 'B' | 'C' | 'D' | 'E';
export type ReviewRecommendation = 'NAIK_GAJI' | 'BONUS' | 'PEMBINAAN' | 'SP' | 'TIDAK_ADA';

/** Qualitative aspects, each 1–5. */
export interface ReviewQualitative {
    attitude: number;
    teamwork: number;
    initiative: number;
    responsibility: number;
}

/** Final-score weights in percent (total 100). Default KPI 60 · kualitatif 25 · kedisiplinan 15. */
export interface ReviewWeights {
    kpi: number;
    qualitative: number;
    discipline: number;
}

export interface PerformanceReview {
    id: number;
    employee_id: number;
    employee?: Pick<Employee, 'id' | 'name' | 'user_id'> & { position?: Employee['position'] };
    year: number;
    /** 1 = Jan–Jun, 2 = Jul–Des */
    semester: 1 | 2;
    kpi_average: string | null;
    kpi_months: number;
    discipline_summary: Record<string, number | string | null> | null;
    discipline_score: string | null;
    qualitative: ReviewQualitative | null;
    qualitative_score: string | null;
    weights: ReviewWeights | null;
    final_score: string | null;
    grade: ReviewGrade | null;
    recommendation: ReviewRecommendation | null;
    notes: string | null;
    status: ReviewStatus;
    return_note: string | null;
    reviewer_id: number;
    reviewer?: Pick<User, 'id' | 'name'>;
    submitted_at: string | null;
    approved_by: number | null;
    approver?: Pick<User, 'id' | 'name'> | null;
    approved_at: string | null;
    acknowledged_at: string | null;
    created_at: string;
}

/**
 * Site Settings / web customization — CEO + SUPERADMIN only (added on
 * request, not in PRD). Singleton — see App\Models\SiteSetting::current().
 * Asset URLs are null until an image is uploaded.
 */
export interface SiteSetting {
    id: number;
    site_name: string;
    site_tagline: string | null;
    login_headline: string | null;
    company_address: string | null;
    company_phone: string | null;
    company_email: string | null;
    logo_url: string | null;
    favicon_url: string | null;
    login_image_url: string | null;
    created_at: string;
    updated_at: string;
}

/** Keys of SiteSetting::ASSETS — the `{asset}` route parameter. */
export type BrandAsset = 'logo' | 'favicon' | 'login_image';

/** SiteSetting::branding() — shared on every page as `site`, guests included. */
export interface SiteBranding {
    name: string;
    tagline: string;
    loginHeadline: string;
    logoUrl: string | null;
    faviconUrl: string | null;
    loginImageUrl: string | null;
}

/**
 * Division dashboards (Sprint 9 decision #6) — PRD §7.1 "Analytics – Per
 * Divisi" (`P`). Payloads built by App\Services\DivisionDashboardService,
 * so keys are camelCase (arrays, not serialized models). Dates are
 * `YYYY-MM-DD`; `since`/`waitingSince`/`at`/`reviewedAt` are ISO datetimes.
 */
export interface MonthOption {
    /** `YYYY-MM` */
    value: string;
    label: string;
}

/** PRD 4.2 — KPI per PIC. On schedule + delayed = total. */
export interface DesignPicKpi {
    /** null = the "Tanpa PIC" row. */
    picId: number | null;
    name: string;
    total: number;
    active: number;
    done: number;
    onSchedule: number;
    delayed: number;
    onTimeRate: number | null;
    avgDelayDays: number | null;
}

export interface DesignKpis {
    summary: { total: number; active: number; done: number; delayedActive: number; onTimeRate: number | null };
    byPic: DesignPicKpi[];
}

/** PRD 4.2 — Tracking Omset Desain (per closing month / per project). */
export interface DesignRevenueMonth {
    month: string;
    label: string;
    omset: number;
    piutang: number;
    projects: number;
}

export interface DesignRevenueProject {
    projectId: number;
    projectName: string;
    designId: number | null;
    client: string;
    pic: string | null;
    status: ProjectStatus;
    /** Deal month = the Project's creation (LeadService::confirmDeal()). */
    closedAt: string;
    contractValue: number;
    paid: number;
    /** contract − paid (includes `unscheduled`). */
    piutang: number;
    /** Contract value no termin covers yet. */
    unscheduled: number;
}

export interface DesignRevenue {
    months: DesignRevenueMonth[];
    totals: { omset: number; paid: number; piutang: number; unscheduled: number; projects: number };
    projects: DesignRevenueProject[];
}

export interface MyDesignRow {
    id: number;
    client: string;
    status: DesignStatus;
    deadline: string | null;
    /** Signed: negative = past the deadline. */
    daysLeft: number | null;
    delayDays: number;
}

export interface MyDesigns {
    openCount: number;
    dueThisWeek: MyDesignRow[];
    overdue: MyDesignRow[];
}

/** PRD 4.3 — one approval-pipeline queue (DRAFT / SUBMITTED / CEO_REVIEW). */
export interface QuotationQueueRow {
    id: number;
    client: string;
    totalAmount: number;
    version: number;
    since: string;
    daysWaiting: number;
    /** Drafts only: the latest decision when it was a rejection. `role` = CEO | PM | CLIENT. */
    lastRejection: { role: string; by: string | null; note: string | null; at: string | null } | null;
}

export interface QuotationQueue {
    total: number;
    rows: QuotationQueueRow[];
}

export interface QuotationMonthlyValue {
    month: string;
    label: string;
    sent: number;
    sentCount: number;
    deal: number;
    dealCount: number;
}

export interface QuotationTurnaround {
    avgDays: number | null;
    count: number;
    avgRejections: number | null;
}

/** PRD 4.4 — Monitor Proyek (Overdue Monitor). */
export interface MonitorStats {
    projects: number;
    onHold: number;
    overdueTasks: number;
    dueToday: number;
    dueThisWeek: number;
    milestonesOverdue: number;
    qaWaiting: number;
    pendingOvertime: number;
}

export interface MonitorProject {
    id: number;
    name: string;
    client: string | null;
    status: ProjectStatus;
    pm: string | null;
    progress: number | null;
    progressDate: string | null;
    milestonesCompleted: number;
    milestonesTotal: number;
    openTasks: number;
    overdueTasks: number;
}

export interface OverdueTaskRow {
    id: number;
    title: string;
    milestone: string | null;
    dueDate: string;
    daysLate: number;
    status: TaskStatus;
    priority: TaskPriority;
}

export interface OverdueProjectGroup {
    projectId: number;
    projectName: string;
    client: string | null;
    total: number;
    maxDaysLate: number;
    assignees: { id: number; name: string; maxDaysLate: number; tasks: OverdueTaskRow[] }[];
}

export interface DueTaskRow {
    id: number;
    title: string;
    projectId: number;
    projectName: string;
    assignee: string | null;
    milestone: string | null;
    dueDate: string;
    isToday: boolean;
    status: TaskStatus;
    priority: TaskPriority;
}

export interface AttentionMilestone {
    id: number;
    name: string;
    projectId: number;
    projectName: string;
    status: MilestoneStatus;
    targetDate: string;
    /** Signed: negative = past the target date. */
    daysLeft: number;
    reason: 'OVERDUE' | 'QA_WAITING' | 'DUE_SOON';
}

export interface PendingOvertimeRow {
    id: number;
    staff: string | null;
    projectId: number;
    projectName: string;
    workDate: string;
    hours: number;
    totalAmount: number;
    reason: string;
    daysWaiting: number;
}

/** PRD 4.6 — Dashboard QA (project/milestone level only, never task detail). */
export interface QaPendingRow {
    id: number;
    projectId: number;
    projectName: string;
    projectProgress: number | null;
    milestoneName: string;
    milestoneTargetDate: string | null;
    /** 1 = first review, 2+ = re-submitted after a rejection. */
    round: number;
    waitingSince: string;
    daysWaiting: number;
}

export interface QaRepeatRejection {
    id: number;
    projectId: number;
    projectName: string;
    milestoneName: string;
    status: QAStatus;
    rejectionCount: number;
    notes: string | null;
    reviewedAt: string | null;
}

export interface QaDashboardStats {
    monthLabel: string;
    approvedThisMonth: number;
    rejectedThisMonth: number;
    rejectionRate: number | null;
    avgReviewHours: number | null;
    reviewSample: number;
    reviewWindowDays: number;
}
