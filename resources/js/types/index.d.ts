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
    | 'KEPALA_DESAIN'
    // Sprint 29 Sub 07 (outside the PRD): inputs on every project, never decides.
    | 'ADMIN_PROYEK';

export interface User {
    id: number;
    name: string;
    /** Sprint 21 — an account has a username, an email, or both (lib/username.ts). */
    username: string | null;
    email: string | null;
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
    /** Sprint 13 Sub 01 — sidebar groups this user folded (shared auth user only). */
    nav_preferences?: { collapsed_groups: string[] };
    /** Sprint 18 Sub 05 — Pengaturan Notifikasi (shared auth user only). */
    notification_preferences?: NotificationPreferences;
}

export interface NotificationPreferences {
    muted_categories: NotificationCategory[];
    sound: boolean;
}

/** Sprint 13 #4 — one row of a "Perlu Tindakan" queue (ActionInboxService). */
export interface InboxItem {
    id: string;
    title: string;
    subtitle: string | null;
    /** ISO timestamp the item started waiting, when meaningful. */
    at: string | null;
    href: string;
}

/** Icon key of a queue — mapped to a lucide icon by InboxGroupCard. */
export type InboxIcon =
    | 'quotation'
    | 'project'
    | 'budget'
    | 'salary'
    | 'review'
    | 'material'
    | 'qa'
    | 'overtime'
    | 'followup'
    | 'termin'
    | 'invoice'
    | 'design'
    | 'kpi'
    | 'task'
    | 'dailyform';

/** One "Perlu Tindakan" queue: count, the first few items, and the list page it comes from. */
export interface InboxGroup {
    key: string;
    label: string;
    description: string;
    icon: InboxIcon;
    count: number;
    /** Sidebar menu the queue's badge is shown on. */
    routeName: string;
    /** "Lihat semua" — the list page with the queue's filter. */
    href: string;
    items: InboxItem[];
}

/** Sprint 15 — one line of the letter's RAB table (App\Support\Letters\QuotationLetter::row()). */
export interface LetterRow {
    no: number;
    description: string;
    p: string | null;
    t: string | null;
    volume: string;
    unit: string | null;
    unitPrice: number;
    total: number;
}

/**
 * Sprint 15 — an offer / invoice as the company's letter, built once on the
 * server (App\Support\Letters\*) for both the PDF and the client's link page.
 */
export interface CompanyLetter {
    kind: 'PENAWARAN' | 'INVOICE';
    company: { name: string; logo: string | null; address: string | null; email: string | null; phone: string | null; instagram: string | null; footer: string;
        /** Letterhead contact icons as SVG data URIs (same as the PDF). */
        icons: Record<'address' | 'email' | 'phone' | 'instagram', string> };
    signer: { name: string | null; title: string | null; signature: string | null };
    number: string | null;
    draft: boolean;
    date: string;
    subject: string;
    recipient: string;
    meta: { label: string; value: string }[];
    intro: string;
    showGroups: boolean;
    groups: { label: string; name: string; subtotal: number; rows: LetterRow[] }[];
    totals: { label: string; amount: number }[];
    total: number;
    totalInWords: string;
    notes: string[];
    paymentTerms: { sequence: number; label: string; percentage: string; amount: number; when: string }[];
    closing: string;
    stamp: string | null;
}

/** Sprint 14 Sub 01 — a link or photo handed to the Estimator with a RAB request. */
export interface QuotationReference {
    id: number;
    quotation_id: number;
    kind: 'LINK' | 'PHOTO';
    url: string | null;
    original_name: string | null;
    size: number | null;
    /** Auth-gated URL of a photo (quotations.references.show); null for a link. */
    photo_url: string | null;
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
    /** Sprint 23 K13 — PM only: approved RAB Proyek this PM may open ("Buka Proyek" pop-up; null when none / not a PM). */
    pendingProjectOpenings?: PendingProjectOpenings | null;
    /** Sprint 13 #5 — menu route name → items waiting there ("Perlu Tindakan", cached 60 s per user). */
    navBadges: Record<string, number>;
    /** Sprint 17 Sub 01 — APP_URL is a local host (`.test`, `.local`, localhost, 127.*): client links won't open on a client's phone. */
    appUrlIsLocal: boolean;
    /** Sprint 18 Sub 04 — VAPID public key for Web Push; null when the server has none (device push off). */
    webPushKey: string | null;
};

/** Sprint 12 #19 — App\Models\ProjectOpening. `MENUNGGU_CEO` = legacy waiting value (Sprint 23 migrated it to MENUNGGU_PM). */
export type ProjectOpeningStatus = 'MENUNGGU_PM' | 'MENUNGGU_CEO' | 'DIBUKA';

export interface ProjectOpening {
    id: number;
    quotation_id: number;
    lead_id: number;
    status: ProjectOpeningStatus;
    /** Sprint 23 K13 — the PM who approved the RAB Proyek (null = any PM opens). */
    opener_id: number | null;
    opener?: { id: number; name: string; is_active: boolean } | null;
    /** Projects/Index only — ProjectOpeningPolicy::open() for the viewer. */
    can_open?: boolean;
    lead: { id: number; client_name: string };
    quotation: { id: number; total_amount: string; version: number; client_approved_at: string | null };
    created_at: string;
}

/** Sprint 23 K14 — the people the "Buka Proyek" form picks from (ProjectService::openingFormOptions()). */
export interface ProjectOpeningFormOptions extends ProjectTeamOptions {
    projectManagers: { id: number; name: string }[];
    assistantPms: { id: number; name: string }[];
}

/** Sprint 23 K15 — Kepala Tim (every active account) and Pengawas (role QA) pickers. */
export interface ProjectTeamOptions {
    teamLeads: { id: number; name: string }[];
    supervisors: { id: number; name: string }[];
}

export interface PendingProjectOpenings extends ProjectOpeningFormOptions {
    openings: ProjectOpening[];
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
    /** Sprint 16 Sub 07 — digits only, `08…` (lib/phone.ts formats it). At least one of phone/email is set. */
    phone: string | null;
    email: string | null;
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
    /** Sprint 16 Sub 08 — Master Kota FK (picked via CitySelect). */
    city_id: number | null;
    city?: Pick<CityOption, 'id' | 'name'> | null;
    /** Lead detail only (K15): a city other than config('daiku.home_city') → survey defaults to "Luar Pekanbaru". */
    is_outside_home_city?: boolean;
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
    /** Lead detail (Sprint 17 Sub 02) — the RAB Jasa Survey paying for it and its invoices. */
    quotation?: (Pick<Quotation, 'id' | 'type' | 'status' | 'version'> & { invoices: Pick<Invoice, 'id' | 'number' | 'status'>[] }) | null;
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
    /** Sprint 12 #16 — opened from an approved RAB Jasa Desain, locked until Finance verifies the invoice. */
    | 'MENUNGGU_BAYAR'
    /** Paid — waiting for a Kepala Desain to assign the architects. */
    | 'MENUNGGU_PENUGASAN'
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
    /** Sprint 23 K19 — who recorded "Desain Disetujui Klien", and when. */
    client_acc_by: number | null;
    client_acc_at: string | null;
    client_approver?: Pick<User, 'id' | 'name'> | null;
    /** Sprint 23 K18 — the WhatsApp group where the client, Marketing and the architects talk. */
    whatsapp_group_url: string | null;
    /** Sprint 23 K19b — who last saved the WhatsApp group / design links. */
    links_updated_by: number | null;
    links_updated_at: string | null;
    links_updater?: Pick<User, 'id' | 'name'> | null;
    /** Sprint 12 #16 — the RAB Jasa Desain it was paid through; null = a pre-Sprint-12 design. */
    quotation_id: number | null;
    quotation?: Pick<Quotation, 'id' | 'lead_id' | 'type' | 'status' | 'total_amount' | 'version'> | null;
    /** Sprint 12 #15 — the Kepala Desain who assigned the team. */
    assigned_by: number | null;
    assigner?: Pick<User, 'id' | 'name'> | null;
    assigned_at: string | null;
    /** Sprint 12 #17 — revisions Marketing asked for. History only since Sprint 23 (K20: no longer written). */
    revision_count: number;
    revisions?: DesignRevision[];
    sent_to_client_at: string | null;
    /** Sprint 22 — history only since Sprint 23 (K20: no longer written). */
    ready_for_client_at: string | null;
    ready_note: string | null;
    created_at: string;
    updated_at: string;
}

/** Sprint 12 #17 — one revision Marketing asked of the architect. */
export interface DesignRevision {
    id: number;
    design_id: number;
    sequence: number;
    note: string;
    requester?: Pick<User, 'id' | 'name'> | null;
    created_at: string;
}

/** Sprint 12 D6 — a message in a design's Arsitek ↔ Estimator thread. */
export interface DesignDiscussionMessage {
    id: number;
    body: string;
    attachment_url: string | null;
    user_name: string | null;
    quotation: { id: number; type: QuotationType; version: number } | null;
    created_at: string;
}

/** DesignService::threadFor() — shared by the Design and Quotation pages. */
export interface DesignDiscussionThread {
    designId: number;
    messages: DesignDiscussionMessage[];
    canPost: boolean;
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
    /** Sprint 23 K8 — approved by PM / Asisten PM, with Marketing (every type). */
    | 'READY_TO_SEND'
    | 'SENT_TO_CLIENT'
    /** Sprint 23 K11 — the client said "Tidak Setuju" on the link; Marketing's turn. */
    | 'CLIENT_REJECTED'
    | 'CLIENT_APPROVED'
    | 'CANCELLED'
    /** Sprint 12 states removed in Sprint 23 (migrated to READY_TO_SEND) — never persisted any more. */
    | 'WAITING_CEO'
    | 'APPROVED_INTERNAL'
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
    project?: Pick<Project, 'id' | 'name'> | null;
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
    /** Sprint 19 Sub 01 — note left with "Tandai Klien Sudah Bayar". */
    payment_note: string | null;
    proof_submitted_at: string | null;
    bank_account_id: number | null;
    bank_account?: Pick<BankAccount, 'id' | 'label'> | null;
    paid_date: string | null;
    verified_by: number | null;
    verifier?: Pick<User, 'id' | 'name'> | null;
    verified_at: string | null;
    reject_reason: string | null;
    rejected_at: string | null;
    /** Sprint 23 K12d — how many times "Tandai Klien Sudah Bayar" was sent (photos of the current one: `attempt === proof_attempt`). */
    proof_attempt?: number;
    /** Sprint 23 K12d — proof photos of every submission, newest attempt first (Finance/CEO/Marketing only). */
    payment_proofs?: InvoicePaymentProof[];
    /** Sprint 23 K12 — Invoice::paymentTypeLabel(): "DP", "Termin 2 dari 4 · 30%", "Pelunasan", "Jasa Desain"… */
    payment_type_label?: string;
    /** Sprint 23 K12 — "DP · Proyek Kopi Oz" (or the client's name before there is a project). */
    payment_label?: string;
    /** Sprint 23 K12b — "Menunggu Konfirmasi Pembayaran DP · Proyek Kopi Oz" while MENUNGGU_VERIFIKASI, else null. */
    status_label?: string | null;
}

/** Sprint 23 K12d — one payment proof photo (App\Models\InvoicePaymentProof); served by `finance.invoices.proofs.show`. */
export interface InvoicePaymentProof {
    id: number;
    invoice_id: number;
    attempt: number;
    width: number;
    height: number;
    size: number;
    uploaded_by: number;
    created_at: string;
    url: string;
    thumb_url: string;
}

/** Sprint 23 K12c — one row of "Informasi Pembayaran" (InvoiceService::leadPayments()). */
export interface LeadPaymentItem {
    key: string;
    kind: InvoiceType;
    label: string;
    context: string | null;
    invoice_id: number | null;
    invoice_number: string | null;
    amount: number;
    paid_amount: number;
    due_date: string | null;
    schedule_note: string | null;
    state: 'BELUM_DITAGIH' | 'BELUM_DIBAYAR' | 'MENUNGGU_KONFIRMASI' | 'LUNAS';
    /** StatusChip domain + status ("Belum Ditagih · Marketing", "Belum Dibayar · Klien", …). */
    domain: 'invoice' | 'termin';
    status: string;
    overdue: boolean;
    paid_date: string | null;
    bank_account: string | null;
    reject_reason: string | null;
    pdf_url: string | null;
}

export interface LeadPayments {
    summary: {
        total: number;
        paid: number;
        awaiting_confirmation: number;
        unpaid: number;
        not_billed: number;
        overdue: number;
        overdue_count: number;
        count: number;
    };
    items: LeadPaymentItem[];
}

/** Sprint 12 #8 — one ✔/✘ mark on a RAB item (App\Models\QuotationItemReview). */
export interface QuotationItemReview {
    id: number;
    quotation_id: number;
    version: number;
    quotation_item_id: number | null;
    item_description: string;
    section_name: string | null;
    /** CEO = Sprint 12 history (the CEO no longer reviews, Sprint 23 K8). */
    stage: 'PM' | 'CEO';
    reviewer_id: number;
    reviewer?: Pick<User, 'id' | 'name'>;
    verdict: 'OK' | 'SALAH';
    note: string | null;
    /** Sprint 23 K7 — copied from this earlier version's ✔ (item unchanged), not marked again. */
    carried_from_version: number | null;
    created_at: string;
}

/** Sprint 23 K5 — App\Models\QuotationItem::QTY_SOURCES (null = typed by the Estimator). */
export type QuotationQtySource = 'P' | 'TL' | 'PxTL';

/** Sprint 23 K9 / K11 — App\Models\QuotationNote (append-only, per version). */
export interface QuotationNote {
    id: number;
    quotation_id: number;
    version: number;
    kind: 'CEO' | 'CLIENT' | 'MARKETING';
    /** Null for the client's note (left on the public link). */
    author_id: number | null;
    author?: Pick<User, 'id' | 'name'> | null;
    note: string;
    created_at: string;
}

export interface QuotationItem {
    id: number;
    quotation_id: number;
    /** Sprint 12 #11 — null = pre-Sprint-12 item, shown under "Umum". */
    section_id: number | null;
    description: string;
    /** Optional dimensions (P, T/L) — Sprint 23 K5 may derive the volume from them. */
    dim_length: number | null;
    dim_width_height: number | null;
    /** Fractional allowed (Sprint 11) — 2.5. */
    qty: number;
    /** Sprint 23 K5 — where the volume came from (null = typed). */
    qty_source: QuotationQtySource | null;
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
    /** Sprint 14 Sub 02 — "Buat RAB → Lainnya": a RAB Proyek with its own name (see quotationTitle()). */
    custom_name: string | null;
    /** Sprint 15 — "377/OFF/Daiku/IX/2026" once this version was sent; the letter's "Catatan". */
    letter_number?: string | null;
    client_notes?: string | null;
    lead_survey_id: number | null;
    /** Sprint 12 #29 — set on a RAB Tambahan: its RAB Fix / project. */
    parent_quotation_id: number | null;
    project_id?: number | null;
    project?: Pick<Project, 'id' | 'name'> | null;
    parent?: Pick<Quotation, 'id' | 'version' | 'total_amount'> | null;
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
    /** Sprint 14 Sub 01 — links & photos sent with the request (internal; loaded on the quotation page). */
    references?: QuotationReference[];
    /** Set when Marketing sends the offer (+14 days), cleared when it returns to DRAFT. */
    valid_until: string | null;
    /** Sprint 12 — Marketing's first / latest "Kirim ke Klien". */
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
    /** Sprint 23 K15 — Kepala Tim: a user (`team_lead_id`) or a typed name (outsider/vendor), one of the two. */
    team_lead_id?: number | null;
    team_lead_name?: string | null;
    team_lead?: Pick<User, 'id' | 'name'> | null;
    /** Sprint 29 Sub 06 — the Pengawas (QA/QC), one or more users with role QA, all equal (was `supervisor_id`). */
    supervisors?: Pick<User, 'id' | 'name'>[];
    /** Sprint 23 K15 — Tanggal Buka Proyek (start_date is never before it). */
    opened_on?: string | null;
    status: ProjectStatus;
    /** Sprint 24 D1 — execution phase (PRODUKSI on projects opened before Sprint 24). */
    phase?: ProjectPhase;
    /**
     * Project progress, % (detail page and the project list) — Sprint 29 Sub 10:
     * progres berbobot when the RAB items carry a wage, else actual activity
     * (DONE ÷ all targets). Null on the list while an actual-activity project has no target.
     */
    actual_progress?: number | null;
    progress_basis?: ProgressBasis;
    /** `withCount` on the project list (Sprint 24 D11). */
    tasks_count?: number;
    done_tasks_count?: number;
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

/** Shown to users as a "Timeline" (Sprint 23 K16); runs start_date → target_date ("Tanggal Selesai", K17). */
export interface Milestone {
    id: number;
    project_id: number;
    project?: Pick<Project, 'id' | 'name'>;
    name: string;
    /** Sprint 23 K17 — required for new timelines, backfilled for older ones. */
    start_date: string | null;
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
    milestone?: Pick<Milestone, 'id' | 'name'> & Partial<Pick<Milestone, 'start_date' | 'target_date'>>;
    title: string;
    description: string | null;
    assignee_id: number | null;
    assignee?: Pick<User, 'id' | 'name'>;
    created_by: number;
    due_date: string | null;
    /** Sprint 24 D6 — the day the target was first planned for (due_date moves on rollover / geser). */
    planned_date?: string | null;
    /** Sprint 24 D8 — how often it rolled to the next working day. */
    rollover_count?: number;
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

/** PRD 4.4 — Progress Log, shown as "Catatan Progress" (Sprint 24 D11: a note; progress = actual activity). */
export interface ProgressLog {
    id: number;
    project_id: number;
    logged_by: number;
    logger?: Pick<User, 'id' | 'name'>;
    /** Only on logs written before Sprint 24 D11. */
    percentage: number | null;
    description: string;
    ref_urls: string[] | null;
    log_date: string;
    created_at: string;
}

/** Sprint 25 — one row of the "Activity Target" table; `actual`/`cum_actual` are null for days still ahead. */
export interface ActivityTargetDay {
    date: string;
    target: number;
    actual: number | null;
    rolled: number;
    /** % of all the project's targets planned up to this day. */
    cum_target: number;
    /** % of all the project's targets finished up to this day. */
    cum_actual: number | null;
}

export interface ActivityTargetWeek extends Omit<ActivityTargetDay, 'date'> {
    start: string;
    end: string;
    days: ActivityTargetDay[];
}

/** Sprint 25 — ProductionService::activityTarget(): daily target vs actual for the whole project, numbers only. */
export interface ActivityTarget {
    today: string;
    summary: {
        total: number;
        done: number;
        /** = Project::actualProgress() */
        progress: number;
        planned_to_date: number;
        target_to_date: number;
        /** progress − target_to_date, in points */
        gap: number;
        rolled: number;
    };
    weeks: ActivityTargetWeek[];
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

/** Sprint 12 #24 — a RAB Fix item not in any budget post yet (ProjectBudgetService::overview()). */
export interface BudgetSourceItem {
    id: number;
    section: string | null;
    /** Sprint 12 #29 — an item of an approved RAB Tambahan. */
    addendum: boolean;
    description: string;
    qty: number;
    unit: string | null;
    unit_price: string;
    total_price: string;
}

export interface BudgetLine {
    id: number;
    quotation_item_id: number | null;
    description: string;
    qty: number;
    unit: string | null;
    unit_price: string;
    /** The line's budget — the RAB item's total. */
    sell_price: string;
    /** Sprint 12 #27 — Σ realisations (corrections included). */
    realized: number;
    realized_qty: number;
    realizations: BudgetRealizationEntry[];
}

/** Sprint 12 #27 — one append-only realisation row (a correction has negative amounts). */
export interface BudgetRealizationEntry {
    id: number;
    qty_actual: number;
    unit_cost: string;
    total_cost: string;
    vendor: string | null;
    note: string | null;
    recorded_by: string | null;
    recorded_at: string;
    /** Set on a correction row: the row it cancels. */
    reverses_id: number | null;
    /** Cancelled by a later correction. */
    is_reversed: boolean;
    /** Recorded through a CEO-approved overrun (#28). */
    via_overrun: boolean;
}

/** Sprint 12 #28 — a realisation held for the CEO. */
export interface PendingOverrun {
    id: number;
    item: string | null;
    amount_over: number;
    reason: string;
    payload: { qty_actual: number; unit_cost: number; vendor_id: number | null; note: string | null };
    requested_by: string | null;
    created_at: string;
}

/** Executive Dashboard queue row (AnalyticsController). */
export interface OverrunQueueItem {
    id: number;
    project: { id: number; name: string };
    post: string;
    item: string;
    amount_over: number;
    reason: string;
    requested_by: string | null;
    created_at: string;
}

export interface BudgetPost {
    id: number;
    name: string;
    total: number;
    realized: number;
    difference: number;
    /** (budget − realisation) / budget, %; null for an empty post. */
    margin: number | null;
    pendingOverrun: PendingOverrun | null;
    lines: BudgetLine[];
}

/** BudgetAllocationLog::ACTION_* */
export type BudgetLogAction =
    | 'post_created'
    | 'post_renamed'
    | 'posts_reordered'
    | 'post_deleted'
    | 'items_allocated'
    | 'items_unallocated';

export interface BudgetLogEntry {
    id: number;
    action: BudgetLogAction;
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
    user_name: string | null;
    created_at: string;
}

/** Sprint 12 #23–#26 — "Alokasi Dana Proyek" tab (CEO / Finance / PM only). */
export interface ProjectBudget {
    /** Decision #23 — after the first verified payment. */
    isOpen: boolean;
    /** The project has a RAB Fix (pre-Sprint-12 projects don't). */
    hasRab: boolean;
    unallocatedItems: BudgetSourceItem[];
    posts: BudgetPost[];
    summary: {
        itemsTotal: number;
        discount: number;
        rounding: number;
        rabTotal: number;
        postsTotal: number;
        unallocatedTotal: number;
        /** Σ posts > items total before the discount — warns, never blocks (#24, Sprint 28 K3). */
        overItems: boolean;
        realizedTotal: number;
    };
    logs: BudgetLogEntry[];
}

export interface Termin {
    id: number;
    project_id: number;
    /** Sprint 12 #29 — the RAB this termin bills (RAB Fix or a RAB Tambahan); null on pre-Sprint-12 termins. */
    quotation_id?: number | null;
    project?: Pick<Project, 'id' | 'name'>;
    milestone_id: number | null;
    milestone?: Pick<Milestone, 'id' | 'name'>;
    /** Sprint 12 #12 — copied from the approved payment scheme (null on pre-Sprint-12 termins). */
    payment_term_id?: number | null;
    trigger?: PaymentTermTrigger | null;
    milestone_name?: string | null;
    /** Set by TerminService::remindInvoices() once the trigger is reached ("Pemicu tercapai", Sprint 28 K7). */
    invoice_reminded_at?: string | null;
    /** Sprint 12 #20 — the invoice Marketing issued for it. */
    invoice_id?: number | null;
    invoice?: (Pick<Invoice, 'id' | 'number' | 'status'> & Partial<Pick<Invoice, 'amount' | 'reject_reason'>>) | null;
    /** Sprint 23 K12 — Termin::paymentLabel(): "DP", "Termin 2 dari 4 · 30%", "Pelunasan", "Pekerjaan Tambahan". */
    payment_label?: string;
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
    /** Sprint 24 D4 — SupervisorPaymentService only. */
    | 'JASA_PENGAWAS'
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
/** Sprint 29 Sub 11 — DIRENCANAKAN: a line of the Pengawas's Rencana Bahan before "Ajukan ke Logistik". */
export type MaterialRequestStatus = 'DIRENCANAKAN' | 'MENUNGGU_PM' | 'DIAJUKAN' | 'DISETUJUI' | 'DITOLAK';

/**
 * Sprint 29 Sub 11 (E4) — where a project material line stands
 * (App\Enums\MaterialOrderStatus, ProjectMaterial::orderStatus()); worded by
 * `materialStatus()` in Components/modules/projects/materialArrival.ts.
 */
export type MaterialOrderStatus =
    | 'DIRENCANAKAN'
    | 'MENUNGGU_PM'
    | 'DIAJUKAN'
    | 'DISETUJUI'
    | 'DITOLAK'
    | 'DIPESAN'
    | 'SEBAGIAN'
    | 'LENGKAP'
    | 'DARI_GUDANG';

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
    request_channel: 'TIM' | 'TUKANG' | 'RENCANA' | null;
    request_reason: string | null;
    photo_link: string | null;
    requested_by: number | null;
    requester?: Pick<User, 'id' | 'name'> | null;
    requested_snapshot: MaterialRequestSnapshot | null;
    submitted_at: string | null;
    review_decision: MaterialRequestDecision | null;
    reject_reason: string | null;
    reviewed_at: string | null;
    /** Sprint 29 Sub 11 — "Dipesan": when / by whom Logistik ordered it, and when it should arrive. */
    ordered_at?: string | null;
    ordered_by?: number | null;
    orderer?: Pick<User, 'id' | 'name'> | null;
    expected_at?: string | null;
    order_note?: string | null;
    /** Appended — ProjectMaterial::orderStatus(). */
    order_status: MaterialOrderStatus;
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

/**
 * Sprint 18 (K3) — mirrors App\Enums\NotificationPriority: P1 a client is
 * waiting, P2 a colleague/project is waiting, P3 news, P4 bell only.
 */
export type NotificationPriority = 'CLIENT_WAITING' | 'ACTION_REQUIRED' | 'UPDATE' | 'INFO';

/** Mirrors App\Enums\NotificationCategory — the groups muted in Pengaturan Notifikasi. */
export type NotificationCategory =
    | 'CRM'
    | 'DESIGN'
    | 'QUOTATION'
    | 'FINANCE'
    | 'PROJECT'
    | 'TASK'
    | 'OVERTIME'
    | 'QA'
    | 'LOGISTICS'
    | 'HR'
    | 'ACCOUNT';

/** PRD 5.1 — Notifications */
export interface AppNotification {
    id: number;
    user_id: number;
    type: string;
    title: string;
    message: string;
    is_read: boolean;
    read_at: string | null;
    metadata: Record<string, unknown> | null;
    priority: NotificationPriority;
    /** null for a type no longer known (an old row). */
    category: NotificationCategory | null;
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

/** Sprint 16 Sub 08 — Master Kota row as sent by `City::options()`. */
export interface CityOption {
    id: number;
    name: string;
    province: string | null;
}

/** Data Master → Kota row (`leads_count` > 0 → rename only). */
export interface CityRow extends CityOption {
    leads_count: number;
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

/** Sprint 29 Sub 13 — App\Enums\DailyActivityReviewStatus (HR's check of one employee's day). */
export type DailyActivityReviewStatus = 'SESUAI' | 'PERLU_KLARIFIKASI' | 'TIDAK_MENGISI';

/** One "Aktivitas Harian" entry (DailyActivityService::presentActivity). */
export interface DailyActivityEntry {
    id: number;
    date: string;
    description: string;
    project: { id: number; name: string } | null;
    created_at: string | null;
    updated_at: string | null;
}

/** HR's newest check of a day (DailyActivityService::presentReview). */
export interface DailyActivityReviewInfo {
    status: DailyActivityReviewStatus;
    note: string | null;
    reviewer: string | null;
    reviewed_at: string | null;
}

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
    /** Sprint 15 — letterhead, signer & default RAB notes. */
    company_instagram: string | null;
    company_legal_name: string | null;
    letter_footer: string | null;
    signer_name: string | null;
    signer_title: string | null;
    note_survey: string | null;
    note_desain: string | null;
    note_proyek: string | null;
    logo_url: string | null;
    favicon_url: string | null;
    login_image_url: string | null;
    /** Data URI — the signature has no public route. */
    signature_url: string | null;
    /** Sprint 20 Sub 05 — "Profil Publik": the company profile's text at `/` (null = placeholder / hidden). */
    public_tagline: string | null;
    hero_headline: string | null;
    hero_subheadline: string | null;
    about_text: string | null;
    founded_year: number | null;
    stat_projects: number | null;
    stat_cities: number | null;
    service_area_text: string | null;
    /** Digits only, `08…` (lib/phone.ts); null = the site falls back to `company_phone`. */
    whatsapp_phone: string | null;
    whatsapp_greeting: string | null;
    maps_embed_url: string | null;
    opening_hours: string | null;
    google_site_verification: string | null;
    hero_image_url: string | null;
    created_at: string;
    updated_at: string;
}

/** Keys of SiteSetting::ASSETS — the `{asset}` route parameter. */
export type BrandAsset = 'logo' | 'favicon' | 'login_image' | 'signature' | 'hero_image';

/** Sprint 20 Sub 05 — SiteSettingController::edit `publicDefaults`: what the site shows while a Profil Publik text is empty. */
export interface PublicProfileDefaults {
    public_tagline: string;
    hero_headline: string;
    hero_subheadline: string;
    about_text: string;
    service_area_text: string;
    opening_hours: string;
    whatsapp_greeting: string;
}

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
    /** Sprint 28 K14 — for the chip's holder (`projectWaitingOn`). */
    phase: ProjectPhase | null;
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
    /** Sprint 28 K14 — for the chip's holder (`projectWaitingOn`). */
    phase: ProjectPhase | null;
    pm: string | null;
    /** Sprint 29 Sub 10 — progres berbobot, else actual activity (DONE ÷ all daily targets; null without targets). */
    progress: number | null;
    progressBasis: ProgressBasis;
    tasksDone: number;
    tasksTotal: number;
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

/**
 * Sprint 19 Sub 05 (K4) — LeadController::show `activeQuotations`: per RAB
 * kind the newest one that isn't cancelled (never a RAB Tambahan), ordered
 * Proyek > Desain > Survey. `title` comes from Quotation::title().
 */
export interface LeadActiveQuotation {
    id: number;
    type: QuotationType;
    title: string;
    version: number;
    status: QuotationStatus;
    total_amount: string;
    valid_until: string | null;
}

/** Sprint 20 — App\Enums\ProjectType::options() (labels a client reads). */
export interface ProjectTypeOption {
    value: ProjectType;
    label: string;
}

/** Sprint 20 Sub 04 — a row of ⚙ Pengaturan → Portofolio (PortfolioController::index). */
export interface PortfolioRow {
    id: number;
    title: string;
    slug: string;
    year: number | null;
    is_published: boolean;
    client_consent: boolean;
    photos_count: number;
    sort_order: number;
    project_type: ProjectType;
    type_label: string;
    /** "Panam, Pekanbaru" — district and city, whichever are known. */
    place: string | null;
    cover_thumb_url: string | null;
    /** Null while unpublished (the public page is a 404 then). */
    public_url: string | null;
    updated_at: string;
}

/** Sprint 20 Sub 04 — PortfolioController::edit `item`. */
export interface PortfolioItemDetail {
    id: number;
    title: string;
    slug: string;
    city_id: number | null;
    location_label: string | null;
    year: number | null;
    summary: string | null;
    description: string | null;
    cover_photo_id: number | null;
    is_published: boolean;
    client_consent: boolean;
    sort_order: number;
    published_at: string | null;
    project_type: ProjectType;
    /** Where the item lives once published (always sent — a 404 until then). */
    public_url: string;
    /** The finished project it was made from ("Jadikan Portofolio"), if any. */
    project: { id: number; name: string } | null;
}

/** Sprint 20 Sub 04 — App\Models\PortfolioPhoto (WebP, max 2000 px + 600 px thumb). */
export interface PortfolioPhoto {
    id: number;
    url: string;
    thumb_url: string;
    width: number;
    height: number;
    alt: string | null;
    caption: string | null;
    sort_order: number;
}

/** Sprint 20 Sub 05 — a row of ⚙ Pengaturan → Testimoni. */
export interface TestimonialRow {
    id: number;
    client_label: string;
    quote: string;
    portfolio_item_id: number | null;
    is_published: boolean;
    sort_order: number;
    portfolio_title: string | null;
}

/** Sprint 20 Sub 06 — one FAQ entry of a service page. */
export interface ServicePageFaq {
    q: string;
    a: string;
}

/** Sprint 20 Sub 06 — a row of ⚙ Pengaturan → Halaman Layanan (one per ServiceCatalog entry). */
export interface ServicePageRow {
    id: number;
    title: string;
    slug: string;
    /** ProjectType labels the page covers ("Toko", "Retail & Toko"). */
    types: string[];
    is_published: boolean;
    /** Intro or body still equals the seeded text — cannot be published yet. */
    still_placeholder: boolean;
    has_hero: boolean;
    /** Published portfolio items of the page's types. */
    portfolio_count: number;
    public_url: string;
    updated_at: string;
}

/** Sprint 20 Sub 06 — the seeded text of a service page (Placeholder::servicePage()). */
export interface ServicePageContent {
    title: string;
    headline: string;
    intro: string;
    body: string;
    highlights: string[];
    faqs: ServicePageFaq[];
    meta_description: string;
}

/** Sprint 20 Sub 06 — ServicePageController::edit `page`. */
export interface ServicePageDetail extends ServicePageContent {
    id: number;
    slug: string;
    is_published: boolean;
    hero_url: string | null;
    public_url: string;
    types: string[];
    still_placeholder: boolean;
}


/** Sprint 24 D1 — the execution phase inside ACTIVE (App\Enums\ProjectPhase). */
export type ProjectPhase = 'GAMBAR_KERJA' | 'TM' | 'PERSIAPAN' | 'PRODUKSI' | 'PEMASANGAN';
/** Sprint 29 Sub 10 — what the project progress is (Project::PROGRESS_*): progres berbobot / actual activity. */
export type ProgressBasis = 'weighted' | 'activity';
export type WorkingDrawingStatus = 'MENUNGGU_PENUGASAN' | 'DIKERJAKAN' | 'SELESAI';
export type SupervisorPaymentKind = 'DP' | 'MINGGUAN' | 'PELUNASAN';
export type SupervisorPaymentStatus = 'DIAJUKAN' | 'DISETUJUI_PM' | 'DITOLAK' | 'DIBAYAR';

/** Sprint 32 — a position in a Tukang team with its default weight (⚙ Pengaturan › Tim Tukang). */
export interface CareerLevelOption {
    id: number;
    name: string;
    weight: number;
    /** The company's share (File D "Perusahaan"), kept out of the team's pay. */
    is_company: boolean;
}

/** Sprint 32 — one person of a team; `percent` = bobot ÷ Σ bobot × 100. */
export interface WageSplitMember {
    name: string | null;
    level: string | null;
    weight: number;
    percent: number;
    company: boolean;
}

export interface WorkTeamTemplate {
    id: number;
    name: string;
    leader_name: string | null;
    members: WageSplitMember[];
}

/** Sprint 32 — WageSplitService::overview(): per Kepala Tim its people, per payment each person's part. */
export interface WageSplitOverview {
    teams: { label: string; template: string | null; members: WageSplitMember[] | null }[];
    slips: {
        id: number;
        label: string;
        kind: SupervisorPaymentKind;
        status: SupervisorPaymentStatus;
        amount: number;
        date: string | null;
        teams: { label: string; amount: number; rows: (WageSplitMember & { amount: number })[] | null }[];
    }[];
    levels: CareerLevelOption[];
    /** Only for who fills it (ProjectPolicy::splitWages()). */
    templates: WorkTeamTemplate[];
}
export type WeeklyReportStatus = 'DIAJUKAN' | 'DISETUJUI' | 'DITOLAK';
export type SiteFindingStatus = 'TERBUKA' | 'SELESAI';
/** Sprint 31 Sub 03 — WorkItemService::SCHEDULE_* */
export type WorkItemScheduleStatus = 'BELUM_DIJADWALKAN' | 'AKAN_DATANG' | 'BERJALAN' | 'TERLAMBAT' | 'SELESAI';

/** Sprint 31 Sub 03 — "Jadwal per Item" (File C's light-blue cells). Dates are Y-m-d. */
export interface WorkSchedule {
    items: {
        id: number;
        description: string;
        volume_label: string;
        team_lead: string;
        progress: number;
        production_start: string | null;
        production_end: string | null;
        installation_start: string | null;
        installation_end: string | null;
        status: WorkItemScheduleStatus;
    }[];
    /** The chart's first and last day (project dates and every window). */
    from: string | null;
    to: string | null;
    today: string;
    project_start: string | null;
    project_end: string | null;
    summary: { total: number; late: number; unscheduled: number };
}

/** Sprint 31 — "Rekap Progres per Item" (WorkItemService::progressRecap()). */
export interface ProgressRecap {
    /** Pengecekan dates, oldest first (Y-m-d). */
    dates: string[];
    items: {
        id: number;
        description: string;
        volume_label: string;
        team_lead: string;
        /** % of the RAB value of the items with a wage. */
        weight: number;
        /** date → % added that day. */
        cells: Record<string, number>;
        total: number;
        /** total × weight ÷ 100 — the item's share of the project progress. */
        contribution: number;
    }[];
    per_date: { date: string; added: number; cumulative: number }[];
    total: number | null;
}

/** Sprint 29 Sub 12 — App\Enums\WorkItemQaStatus (a check row is only LOLOS / PERBAIKAN). */
export type WorkItemQaStatus = 'BELUM' | 'MENUNGGU' | 'PERBAIKAN' | 'LOLOS';

/** Sprint 29 Sub 12 — WorkItemQaService::overview(): Timeline & QC › QC Item. */
export interface ItemQaOverview {
    items: {
        id: number;
        description: string;
        volume_label: string;
        team_lead: string;
        progress: number;
        status: WorkItemQaStatus;
        /** Number of QA decisions so far (append-only). */
        checks: number;
        latest: {
            id: number;
            decision: Extract<WorkItemQaStatus, 'LOLOS' | 'PERBAIKAN'>;
            note: string | null;
            photo_url: string | null;
            /** Streamed from the private disk (projects.item-qa.photo). */
            photo_href: string | null;
            checker: string | null;
            at: string | null;
            finding: {
                id: number;
                description: string;
                status: SiteFindingStatus;
                task: { title: string; status: TaskStatus; assignee: string | null; due_date: string | null } | null;
            } | null;
        } | null;
    }[];
    summary: { total: number; belum: number; menunggu: number; perbaikan: number; lolos: number };
    can: { decide: boolean; repair: boolean; resolve: boolean };
}

/** One line of a checklist (TM Selesai, syarat DP). */
export interface ExecutionRequirement {
    key: string;
    label: string;
    done: boolean;
    /** Sprint 27 P1a — DP conditions only: what is missing, named items (task titles only for task viewers), and how many more. */
    detail?: string | null;
    missing?: string[];
    more?: number;
}

/** Sprint 24 D2 — WorkingDrawingController::show `drawing`. */
export interface WorkingDrawing {
    id: number;
    project_id: number;
    project?: Pick<Project, 'id' | 'name' | 'phase' | 'start_date' | 'end_date'> & {
        lead?: Pick<Lead, 'id' | 'client_name'>;
        pm?: Pick<User, 'id' | 'name'> | null;
        supervisors?: Pick<User, 'id' | 'name'>[];
    };
    status: WorkingDrawingStatus;
    pic_id: number | null;
    pic?: Pick<User, 'id' | 'name'> | null;
    staff?: Pick<User, 'id' | 'name'>[];
    assigner?: Pick<User, 'id' | 'name'> | null;
    assigned_at: string | null;
    start_date: string | null;
    target_hari: number | null;
    deadline: string | null;
    drawing_urls: string[] | null;
    note: string | null;
    links_updater?: Pick<User, 'id' | 'name'> | null;
    links_updated_at: string | null;
    completer?: Pick<User, 'id' | 'name'> | null;
    completed_at: string | null;
    created_at: string;
}

/** Sprint 24 D4 — a Jasa Pengawas payment (Finance queue row). */
export interface SupervisorPayment {
    id: number;
    project_id: number;
    project?: Pick<Project, 'id' | 'name'>;
    supervisor?: Pick<User, 'id' | 'name'> | null;
    kind: SupervisorPaymentKind;
    /** "DP · Proyek Kopi Oz" — SupervisorPayment::label (Sprint 23 K12b style). */
    label: string;
    amount: string;
    /** Sprint 29 Sub 09 — split per Kepala Tim, frozen when asked (null for older projects). */
    breakdown?: WageShare[] | null;
    status: SupervisorPaymentStatus;
    decider?: Pick<User, 'id' | 'name'> | null;
    decided_at: string | null;
    payer?: Pick<User, 'id' | 'name'> | null;
    paid_at: string | null;
}

/** Sprint 29 Sub 09 — one Kepala Tim's part of an Upah Tukang payment (WorkItemService::teamShares()). */
export interface WageShare {
    label: string;
    amount: number;
}

/** Sprint 29 Sub 09 — the pengecekan % per item (WorkItemService::checkOverview()). */
export interface WorkCheck {
    items: {
        id: number;
        description: string;
        volume_label: string;
        team_lead: string;
        wage_subtotal: number;
        rab_subtotal: number;
        weight: number;
        /** Σ % recorded so far (0–100). */
        progress: number;
    }[];
    summary: {
        total_wage: number;
        weighted_progress: number | null;
        payable: number;
        /** Null for viewers who don't see the payments (Admin Proyek). */
        already_paid: number | null;
        payable_now: number | null;
        all_done: boolean;
        teams: { label: string; total_wage: number; progress: number | null; payable: number }[];
    };
    draft: {
        entries: Record<string, { percent: number | null; note: string | null }>;
        notes: string | null;
        links: string[];
        updated_by: string | null;
        updated_at: string | null;
    } | null;
}

/** Sprint 29 Sub 08 — one RAB item of the Upah Tukang card (WorkItemService::overview()). */
export interface WorkItemRow {
    id: number;
    description: string;
    volume: number;
    volume_label: string;
    unit: string | null;
    unit_price: number;
    rab_subtotal: number;
    unit_wage: number | null;
    wage_subtotal: number;
    /** % of the RAB value of the items with a wage; null without a wage. */
    weight: number | null;
    team_lead_id: number | null;
    team_lead_name: string | null;
    team_lead: string | null;
}

/** Sprint 24 — ProjectExecutionService::overview(), the project's "Eksekusi" tab. */
/** Sprint 33 — ProjectStepsService: a step of the running phase, in the order it is done. */
/** `optional` — beside the flow (e.g. the Pengawas's upah & bahan at TM): never holds the phase's closing step. */
export type WorkStepState = 'done' | 'current' | 'ongoing' | 'optional' | 'todo';

export interface WorkSteps {
    phase: ProjectPhase;
    phase_label: string;
    items: {
        key: string;
        title: string;
        who: string;
        detail: string | null;
        target: { tab: string; view?: string; anchor?: string };
        /** This viewer is the one who does it. */
        mine: boolean;
        state: WorkStepState;
        /** A step done by one button of its own instead of opening a card (e.g. `completeMeeting`). */
        action: 'completeMeeting' | 'decidePayment' | null;
    }[];
    done: number;
    total: number;
    next: { phase: string; title: string } | null;
}

export interface ProjectExecution {
    phase: ProjectPhase;
    /** Sprint 33 — "Langkah Kerja" (null when the project is not running). */
    steps: WorkSteps | null;
    /** Sprint 29 Sub 08 — wages per RAB item (the total is no longer typed at the TM). */
    usesWorkItems: boolean;
    /** Sprint 29 Sub 08 — the Upah Tukang card (null for viewers who don't see RAB prices). */
    workItems: {
        sections: { id: string; title: string; items: WorkItemRow[] }[];
        summary: { total_wage: number; items: number; paid_items: number; unpaid_items: number; per_team_lead: { label: string; total: number; items: number }[] };
    } | null;
    /** Sprint 29 Sub 09 — the pengecekan % per item (production, projects with wages per item; null otherwise). */
    workCheck: WorkCheck | null;
    phases: { value: ProjectPhase; label: string; state: 'done' | 'current' | 'upcoming' }[];
    isLegacy: boolean;
    workingDrawing: {
        id: number;
        status: WorkingDrawingStatus;
        pic: string | null;
        staff: string[];
        deadline: string | null;
        drawing_urls: string[];
        note: string | null;
        completed_at: string | null;
        completer: string | null;
        canOpen: boolean;
    } | null;
    meeting: {
        meeting_date: string | null;
        /** Sprint 34 — "10:00"; JADWAL = invited ahead (reminders), LANGSUNG = held right away. */
        meeting_time: string | null;
        mode: 'JADWAL' | 'LANGSUNG' | null;
        invited_at: string | null;
        notes: string | null;
        attendees: { user_id: number | null; name: string | null; label: string }[];
        completed_at: string | null;
        completer: string | null;
    } | null;
    meetingRequirements: ExecutionRequirement[];
    meetingMaterials: {
        id: number;
        material_id: number | null;
        name: string;
        qty: string;
        qty_planned: number;
        unit: string | null;
        source: ProjectMaterialSource;
        /** Sprint 29 Sub 11 — RENCANA = the Pengawas's Rencana Bahan; its status and reason. */
        qty_received: number;
        channel: ProjectMaterial['request_channel'];
        order_status: MaterialOrderStatus;
        reject_reason: string | null;
        expected_at: string | null;
        editable: boolean;
    }[];
    meetingRab: { id: string; title: string; items: { id: number; description: string; volume: string }[] }[];
    attendeeOptions: Pick<User, 'id' | 'name'>[];
    defaultEndDate: string | null;
    fee: { total_amount: number; dp_percent: number; dp_amount: number; committed: number; paid: number; remaining: number } | null;
    dpRequirements: ExecutionRequirement[];
    payments: {
        id: number;
        kind: SupervisorPaymentKind;
        label: string;
        amount: number;
        breakdown: WageShare[] | null;
        /** Sprint 29 Sub 09 — decided with its pengecekan (weekly report still DIAJUKAN). */
        via_report: boolean;
        status: SupervisorPaymentStatus;
        requested_at: string | null;
        decided_at: string | null;
        decider: string | null;
        decision_note: string | null;
        paid_at: string | null;
    }[];
    weeklyReports: {
        id: number;
        week_number: number;
        week_start: string;
        /** Sprint 31 — several pengecekan a week: 1, 2, … within its week. */
        sequence: number;
        /** WeeklyReport::label() — "Minggu ke-3" / "Minggu ke-3 · Pengecekan ke-2". */
        label: string;
        notes: string;
        links: string[];
        status: WeeklyReportStatus;
        supervisor: string | null;
        submitted_at: string | null;
        decided_at: string | null;
        decider: string | null;
        decision_note: string | null;
        payment_amount: number | null;
        /** Sprint 29 Sub 09 — the request this pengecekan asked (money viewers only). */
        payment: { id: number; kind: SupervisorPaymentKind; amount: number; status: SupervisorPaymentStatus; breakdown: WageShare[] | null } | null;
        /** Sprint 29 Sub 09 — the % added per item with this report. */
        progress: { description: string | null; percent: number; note: string | null }[];
    }[];
    /** `is_due` — Sprint 29 Sub 02: from the report day (Friday) on; it may be sent any day. */
    /** `open` — Sprint 31: another report / pengecekan may be sent now (WeeklyReportService::canSubmit()). */
    currentWeek: { week_start: string; week_number: number; is_report_day: boolean; is_due: boolean; open: boolean } | null;
    /** Sprint 31 — WorkItemService::progressRecap(): File C as a table (pengecekan viewers only). */
    progressRecap: ProgressRecap | null;
    /** Sprint 31 Sub 03 — WorkItemService::scheduleOverview() (from Persiapan, projects with wages per item). */
    workSchedule: WorkSchedule | null;
    /** Sprint 32 — WageSplitService::overview(): Pembagian Upah per Orang (who sees the Upah Tukang). */
    wageSplit: WageSplitOverview | null;
    /** Project::actualProgress() — Sprint 29 Sub 10: progres berbobot or actual activity (`progressBasis`). */
    progress: number;
    progressBasis: ProgressBasis;
    threshold: number;
    departure: { approved_at: string; approver: string | null } | null;
    findings: {
        id: number;
        description: string;
        photo_url: string | null;
        status: SiteFindingStatus;
        milestone: { id: number; name: string } | null;
        task: { id: number; title: string; status: TaskStatus; assignee: string | null; due_date: string | null } | null;
        reporter: string | null;
        created_at: string | null;
    }[];
    targetVsActual: {
        week_start: string;
        days: string[];
        rows: { staff_id: number; name: string; target: number; actual: number; cells: Record<string, { target: number; actual: number; rolled: number }> }[];
    } | null;
    deadline: { end_date: string | null; warn_from: string | null; warning: boolean; days_left: number | null };
    can: {
        saveMeeting: boolean;
        /** Sprint 29 Sub 08 — the project's Pengawas or the Admin Proyek fills the Upah Tukang card. */
        setWages: boolean;
        /** Sprint 31 Sub 03 — the Jadwal per Item card's dates (Pengawas / Admin Proyek). */
        scheduleItems: boolean;
        /** Sprint 32 — Item RAB › Pembagian Upah (Pengawas / Admin Proyek). */
        splitWages: boolean;
        completeMeeting: boolean;
        requestDp: boolean;
        decide: boolean;
        submitWeekly: boolean;
        approveDeparture: boolean;
        recordFinding: boolean;
        resolveFinding: boolean;
        manageTargets: boolean;
        requestSettlement: boolean;
        /** Sprint 29 Sub 09 — the pengecekan draft: the project's Pengawas or the Admin Proyek. */
        fillWorkCheck: boolean;
        planMaterials: boolean;
        updateMaterials: boolean;
        removeMaterials: boolean;
        /** Sprint 29 Sub 11 — custom lines + "Ajukan ke Logistik" (the Pengawas / Admin Proyek). */
        submitMaterialPlan: boolean;
    };
}
