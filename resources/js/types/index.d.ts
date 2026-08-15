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

/** PRD 4.6 — QA */
export type QAStatus = 'PENDING' | 'APPROVED' | 'REJECTED';

/** PRD 4.5 / Overtime schema — Pengajuan Lembur */
export type OvertimeStatus =
    | 'PENDING'
    | 'APPROVED_PM'
    | 'APPROVED_FINANCE'
    | 'REJECTED';

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
