// Shared TypeScript interfaces for the Daiku Interior system.
// Mirrors the roles, enums and entities defined in PRD section 5 (Database
// Schema & Entities) and section 7 (RBAC). Extend per-module as controllers
// and Inertia pages are built out.

/** PRD 2 — Stakeholders & Users */
export type Role =
    | 'CEO'
    | 'MARKETING'
    | 'DESIGNER'
    | 'ESTIMATOR'
    | 'PM'
    | 'QA'
    | 'FINANCE'
    | 'LOGISTICS'
    | 'FIELD_STAFF';

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

/** PRD 4.3 — Quotation / RAB */
export type QuotationStatus =
    | 'DRAFT'
    | 'SUBMITTED'
    | 'CEO_REVIEW'
    | 'PM_REVIEW'
    | 'SENT_TO_CLIENT'
    | 'APPROVED'
    | 'REJECTED';

/** PRD 4.4 — Project Management */
export type ProjectStatus = 'ACTIVE' | 'COMPLETED' | 'ON_HOLD' | 'CANCELLED';
export type MilestoneStatus =
    | 'PENDING'
    | 'IN_PROGRESS'
    | 'QA_WAITING'
    | 'COMPLETED'
    | 'OVERDUE';

/** PRD 4.5 — Task Management (Field Staff) */
export type TaskStatus = 'PENDING' | 'ONPROGRESS' | 'PENGECEKAN' | 'DONE' | 'OVER';
export type TaskPriority = 'HIGH' | 'MEDIUM' | 'LOW';

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
