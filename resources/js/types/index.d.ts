// Shared TypeScript interfaces for the Daiku Interior system.
// Mirrors the roles, enums and entities defined in PRD section 5 (Database
// Schema & Entities) and section 7 (RBAC). Extend per-module as controllers
// and Inertia pages are built out.

/**
 * PRD 2 — Stakeholders & Users. `SUPERADMIN` is a technical admin role
 * added outside the PRD — see database/seeders/RoleSeeder.php.
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
    | 'SUPERADMIN';

export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    role?: Role;
    is_active?: boolean;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    /** Shared on every Inertia navigation (see HandleInertiaRequests) — not real-time, see AppNotification. */
    notifications: AppNotification[];
};

/** Shape of a Laravel paginator (`->paginate()`) as sent to Inertia props. */
export interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
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
    category: LeadCategory | null;
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
    follow_up_date: string | null;
    lost_reason: string | null;
    notes: string | null;
    created_by: number;
    created_at: string;
    updated_at: string;
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

export interface Design {
    id: number;
    lead_id: number;
    pic_id: number | null;
    pic?: Pick<User, 'id' | 'name'>;
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
export type QuotationStatus =
    | 'DRAFT'
    | 'SUBMITTED'
    | 'CEO_REVIEW'
    | 'PM_REVIEW'
    | 'SENT_TO_CLIENT'
    | 'APPROVED'
    | 'REJECTED';

export interface QuotationItem {
    id: number;
    quotation_id: number;
    description: string;
    qty: number;
    unit: string;
    unit_price: string;
    total_price: string;
    sort_order: number;
}

export interface QuotationApproval {
    id: number;
    quotation_id: number;
    approver_id: number;
    approver?: Pick<User, 'id' | 'name'>;
    approver_role: 'CEO' | 'PM';
    status: 'APPROVED' | 'REJECTED';
    note: string | null;
    created_at: string;
}

export interface Quotation {
    id: number;
    lead_id: number;
    lead?: Pick<Lead, 'id' | 'client_name'>;
    total_amount: string;
    status: QuotationStatus;
    valid_until: string | null;
    version: number;
    created_by: number;
    items?: QuotationItem[];
    approvals?: QuotationApproval[];
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

export interface Task {
    id: number;
    project_id: number;
    project?: Pick<Project, 'id' | 'name'>;
    milestone_id: number | null;
    milestone?: Pick<Milestone, 'id' | 'name'>;
    title: string;
    description: string | null;
    assignee_id: number | null;
    assignee?: Pick<User, 'id' | 'name'>;
    created_by: number;
    due_date: string | null;
    status: TaskStatus;
    priority: TaskPriority;
    is_locked: boolean;
    kendala: string | null;
    note: string | null;
    rate_per_task: string | null;
    completed_at: string | null;
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
    | 'APPROVED_PM'
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

/** PRD 6.5 — Logika Penalti Harian */
export interface Penalty {
    id: number;
    staff_id: number;
    staff?: Pick<User, 'id' | 'name'>;
    type: string;
    reference_id: number | null;
    amount: string;
    date_occurred: string;
    is_deducted: boolean;
    created_at: string;
}

/** PRD 4.7 — Dana Family Gathering */
export interface FamilyGatheringFund {
    id: number;
    type: 'INCOME' | 'EXPENSE';
    amount: string;
    description: string | null;
    source_penalty_id: number | null;
    sourcePenalty?: Pick<Penalty, 'id'> & { staff?: Pick<User, 'id' | 'name'> };
    recorded_by: number;
    recorder?: Pick<User, 'id' | 'name'>;
    created_at: string;
}

/** PRD 4.4/4.7/6.4 — Termin (Finance – Termin) */
export type TerminStatus = 'SCHEDULED' | 'INVOICED' | 'PAID' | 'OVERDUE';

export interface Termin {
    id: number;
    project_id: number;
    project?: Pick<Project, 'id' | 'name'>;
    milestone_id: number | null;
    milestone?: Pick<Milestone, 'id' | 'name'>;
    termin_number: number;
    percentage: number;
    amount: string;
    scheduled_date: string;
    status: TerminStatus;
    bank_account_id: number | null;
    bankAccount?: Pick<BankAccount, 'id' | 'label'>;
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
    | 'LAINNYA';

export interface FinanceTransaction {
    id: number;
    project_id: number | null;
    project?: Pick<Project, 'id' | 'name'> | null;
    bank_account_id: number | null;
    bankAccount?: Pick<BankAccount, 'id' | 'label'> | null;
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

export interface BankAccount {
    id: number;
    bank_name: string;
    account_no: string;
    label: string;
    balance: string;
    is_active: boolean;
    created_at: string;
    updated_at: string;
}

/**
 * Site Settings — general company/application profile, CEO + SUPERADMIN
 * only (added on request, not in PRD). Singleton — see
 * App\Models\SiteSetting::current().
 */
export interface SiteSetting {
    id: number;
    site_name: string;
    company_address: string | null;
    company_phone: string | null;
    company_email: string | null;
    company_logo_url: string | null;
    created_at: string;
    updated_at: string;
}
