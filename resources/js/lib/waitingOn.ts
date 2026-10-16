/**
 * Sprint 23 K3 — "menunggu siapa": every status that is still running names
 * whose turn it is ("Menunggu Review · PM / Asisten PM", "Dikirim · Klien").
 * One map per domain, because the same code means different things in
 * different domains (PENDING is a task, an overtime, a QA form…). Finished
 * and cancelled statuses have no holder.
 *
 * Read through `<StatusChip domain="quotation" status={…} />` (or
 * `waitingOn('quotation', status)` where a chip isn't the right shape) —
 * never write the holder text per page.
 */

export type WaitingDomain =
    | 'quotation'
    | 'lead'
    | 'leadSurvey'
    | 'design'
    | 'invoice'
    | 'termin'
    | 'project'
    | 'projectOpening'
    | 'milestone'
    | 'task'
    | 'qa'
    | 'overtime'
    | 'materialRequest'
    | 'salaryChange'
    | 'performanceReview'
    | 'kpiPeriod'
    | 'penalty'
    | 'staffLoan'
    | 'supplierDebt'
    | 'assetInstallment'
    | 'payroll'
    // Sprint 24 — alur eksekusi proyek.
    | 'projectPhase'
    | 'workingDrawing'
    | 'supervisorPayment'
    | 'weeklyReport'
    | 'siteFinding';

/** Who holds the turn, per domain → status. Missing = nobody (done/cancelled). */
const HOLDER: Record<WaitingDomain, Partial<Record<string, string>>> = {
    // Sprint 23 §3C — PM ACC goes straight to Marketing; the CEO only gets
    // an info notification. WAITING_CEO/APPROVED_INTERNAL are legacy rows.
    quotation: {
        DIMINTA: 'Estimator',
        DRAFT: 'Estimator',
        SUBMITTED: 'PM / Asisten PM',
        WAITING_CEO: 'CEO',
        APPROVED_INTERNAL: 'Marketing',
        READY_TO_SEND: 'Marketing',
        SENT_TO_CLIENT: 'Klien',
        CLIENT_REJECTED: 'Marketing',
    },
    lead: {
        FOLLOW_UP: 'Marketing',
        DEAL_DESAIN: 'Marketing',
    },
    // Marketing schedules the survey and records its result (crm.surveys.*);
    // outside the home city it first waits for the Jasa Survey payment.
    leadSurvey: {
        DIJADWALKAN: 'Marketing',
        MENUNGGU_BAYAR: 'Klien',
        SIAP: 'Marketing',
    },
    // Sprint 23 §3G — after assignment everything runs in the WhatsApp
    // group; the architect works until "Desain Disetujui Klien".
    design: {
        MENUNGGU_BAYAR: 'Klien',
        MENUNGGU_PENUGASAN: 'Kepala Desain',
        BRIEF: 'Arsitek',
        DESAIN: 'Arsitek',
        WAITING_ACC_DESAIN: 'Klien',
        REVISI_DESAIN: 'Arsitek',
        // Pre-Sprint-12 designs paused by the client.
        HOLD_CLIENT: 'Klien',
        REVISI_CLIENT: 'Arsitek',
    },
    // Sprint 23 K12c — Belum ditagih · Marketing → Belum dibayar · Klien →
    // Menunggu konfirmasi · Finance → Lunas. A termin's holder once it is
    // invoiced follows its invoice (TerminStatusChip passes `waitingOn`).
    invoice: {
        DITERBITKAN: 'Klien',
        MENUNGGU_VERIFIKASI: 'Finance',
    },
    termin: {
        SCHEDULED: 'Marketing',
        INVOICED: 'Klien',
        OVERDUE: 'Klien',
    },
    project: {
        ACTIVE: 'PM',
        ON_HOLD: 'PM',
    },
    // Sprint 23 K13 — the PM who approved the RAB Proyek opens it (pass
    // `waitingOn={opener.name}` where the designated PM is known).
    // MENUNGGU_CEO is the Sprint 12 value, migrated to MENUNGGU_PM.
    projectOpening: {
        MENUNGGU_PM: 'PM',
        MENUNGGU_CEO: 'PM',
    },
    milestone: {
        PENDING: 'PM',
        IN_PROGRESS: 'Tukang',
        QA_WAITING: 'Pengawas (QA/QC)',
        OVERDUE: 'PM',
    },
    // TaskService — the assignee moves it (an OVER task too, until DONE);
    // "Minta dicek" waits for the project's PM / Asisten PM (TaskPolicy::updateStatus).
    task: {
        PENDING: 'Tukang',
        ONPROGRESS: 'Tukang',
        PENGECEKAN: 'PM / Asisten PM',
        OVER: 'Tukang',
    },
    // QaFormService — QA reviews; a rejection is fixed and re-sent by the
    // project's PM / Asisten PM (Selesaikan timeline → resubmit).
    qa: {
        PENDING: 'Pengawas (QA/QC)',
        REJECTED: 'PM / Asisten PM',
    },
    // pmApprove is `role:PM` only — the Asisten PM doesn't decide overtime.
    overtime: {
        PENDING: 'PM',
        PENDING_FINANCE: 'Finance',
    },
    // MaterialRequestService — a Tukang's request goes to the PM / Asisten PM
    // (pmDecision) first; Logistics decides every DIAJUKAN line.
    materialRequest: {
        MENUNGGU_PM: 'PM / Asisten PM',
        DIAJUKAN: 'Logistik',
    },
    salaryChange: {
        PENDING: 'CEO',
    },
    // ReviewStatus — HR drafts/submits, the CEO approves or returns, the
    // employee acknowledges (my.reviews.acknowledge).
    performanceReview: {
        DRAFT: 'HR',
        SUBMITTED: 'CEO',
        APPROVED: 'Karyawan',
    },
    // KpiService — HR fills manual values and closes the month.
    kpiPeriod: {
        OPEN: 'HR',
    },
    // The tukang pays (cash/transfer, wages are not cut); Finance records it.
    penalty: {
        BELUM_DIBAYAR: 'Tukang',
    },
    // Statuses derived from `remaining`/`due_date` (StaffLoan, SupplierDebt,
    // Asset installments): the borrower repays a staff loan; Finance pays
    // suppliers, asset installments and the monthly payroll.
    staffLoan: {
        BERJALAN: 'Tukang',
    },
    supplierDebt: {
        BERJALAN: 'Finance',
        JATUH_TEMPO: 'Finance',
    },
    assetInstallment: {
        BERJALAN: 'Finance',
        JATUH_TEMPO: 'Finance',
    },
    payroll: {
        PENDING: 'Finance',
    },
    // Sprint 24 D1 — who moves the project on to the next phase.
    projectPhase: {
        GAMBAR_KERJA: 'Kepala Desain / Arsitek',
        TM: 'PM',
        PERSIAPAN: 'Pengawas → PM',
        PRODUKSI: 'Pengawas & Tukang',
        PEMASANGAN: 'Pengawas',
    },
    workingDrawing: {
        MENUNGGU_PENUGASAN: 'Kepala Desain',
        DIKERJAKAN: 'Arsitek',
    },
    // SupervisorPaymentService — the PM decides, Finance pays.
    supervisorPayment: {
        DIAJUKAN: 'PM',
        DISETUJUI_PM: 'Finance',
    },
    weeklyReport: {
        DIAJUKAN: 'PM',
        DITOLAK: 'Pengawas',
    },
    siteFinding: {
        TERBUKA: 'Pengawas / Tukang',
    },
};

/**
 * Domain-specific status labels — where the global label (StatusChip's
 * humanize) would read wrong for this domain ("Submitted" of a RAB is
 * "Menunggu Review", of a performance review it is "Diajukan").
 */
const LABEL: Partial<Record<WaitingDomain, Partial<Record<string, string>>>> = {
    quotation: {
        DIMINTA: 'Diminta',
        DRAFT: 'Draft',
        SUBMITTED: 'Menunggu Review',
        WAITING_CEO: 'Menunggu CEO',
        APPROVED_INTERNAL: 'Disetujui Internal',
        READY_TO_SEND: 'Siap Dikirim',
        SENT_TO_CLIENT: 'Dikirim',
        CLIENT_REJECTED: 'Ditolak Klien',
        CLIENT_APPROVED: 'Disetujui Klien',
        CANCELLED: 'Dibatalkan',
    },
    design: {
        DESAIN: 'Sedang Didesain',
        WAITING_ACC_DESAIN: 'Menunggu ACC Klien',
        REVISI_DESAIN: 'Revisi Desain',
        ACC_DESAIN: 'Disetujui Klien',
    },
    invoice: {
        DITERBITKAN: 'Belum Dibayar',
        // Sprint 23 K12b — lists show the full Invoice::confirmationLabel() via `status_label`.
        MENUNGGU_VERIFIKASI: 'Menunggu Konfirmasi',
        TERVERIFIKASI: 'Lunas',
    },
    termin: {
        SCHEDULED: 'Belum Ditagih',
        INVOICED: 'Ditagih',
        PAID: 'Lunas',
        OVERDUE: 'Terlambat',
    },
    project: {
        ACTIVE: 'Berjalan',
        ON_HOLD: 'Ditahan',
        COMPLETED: 'Selesai',
        CANCELLED: 'Dibatalkan',
    },
    projectOpening: {
        MENUNGGU_PM: 'Menunggu Dibuka',
        MENUNGGU_CEO: 'Menunggu Dibuka',
        DIBUKA: 'Dibuka',
    },
    milestone: {
        PENDING: 'Belum Mulai',
        IN_PROGRESS: 'Dikerjakan',
        QA_WAITING: 'Belum Diperiksa',
        COMPLETED: 'Selesai',
        OVERDUE: 'Terlambat',
    },
    task: {
        PENDING: 'Belum Mulai',
        ONPROGRESS: 'Dikerjakan',
        PENGECEKAN: 'Pengecekan',
        DONE: 'Selesai',
        OVER: 'Terlambat',
    },
    qa: {
        PENDING: 'Belum Diperiksa',
        APPROVED: 'Lolos',
        REJECTED: 'Perlu Perbaikan',
    },
    overtime: {
        PENDING: 'Menunggu ACC',
        PENDING_FINANCE: 'Menunggu ACC',
        APPROVED_FINANCE: 'Disetujui',
        REJECTED: 'Ditolak',
    },
    materialRequest: {
        MENUNGGU_PM: 'Menunggu ACC',
        DIAJUKAN: 'Menunggu ACC',
        DISETUJUI: 'Disetujui',
        DITOLAK: 'Ditolak',
    },
    salaryChange: {
        PENDING: 'Menunggu Persetujuan',
        APPROVED: 'Disetujui',
        REJECTED: 'Ditolak',
    },
    performanceReview: {
        DRAFT: 'Draf',
        SUBMITTED: 'Diajukan',
        APPROVED: 'Disetujui',
        ACKNOWLEDGED: 'Sudah Dibaca',
    },
    kpiPeriod: {
        OPEN: 'Terbuka',
        CLOSED: 'Ditutup',
    },
    penalty: {
        BELUM_DIBAYAR: 'Belum Dibayar',
        LUNAS: 'Lunas',
    },
    staffLoan: {
        BERJALAN: 'Berjalan',
        LUNAS: 'Lunas',
    },
    supplierDebt: {
        BERJALAN: 'Berjalan',
        JATUH_TEMPO: 'Jatuh Tempo',
        LUNAS: 'Lunas',
    },
    assetInstallment: {
        BERJALAN: 'Berjalan',
        JATUH_TEMPO: 'Jatuh Tempo',
        LUNAS: 'Lunas',
    },
    payroll: {
        PENDING: 'Belum Dibayar',
        PAID: 'Dibayar',
    },
    projectPhase: {
        GAMBAR_KERJA: 'Gambar Kerja',
        TM: 'Technical Meeting',
        PERSIAPAN: 'Persiapan Produksi',
        PRODUKSI: 'Produksi',
        PEMASANGAN: 'Pemasangan',
    },
    workingDrawing: {
        MENUNGGU_PENUGASAN: 'Menunggu Penugasan',
        DIKERJAKAN: 'Dikerjakan',
        SELESAI: 'Selesai',
    },
    supervisorPayment: {
        DIAJUKAN: 'Diajukan',
        DISETUJUI_PM: 'Disetujui PM',
        DITOLAK: 'Ditolak',
        DIBAYAR: 'Dibayar',
    },
    weeklyReport: {
        DIAJUKAN: 'Diajukan',
        DISETUJUI: 'Disetujui',
        DITOLAK: 'Ditolak',
    },
    siteFinding: {
        TERBUKA: 'Terbuka',
        SELESAI: 'Selesai',
    },
};

/** Whose turn it is (`null` when the status is finished / cancelled / unknown). */
export function waitingOn(domain: WaitingDomain, status: string | null | undefined): string | null {
    if (!status) {
        return null;
    }

    return HOLDER[domain][status] ?? null;
}

/** The domain's own wording for a status, if it has one (else StatusChip's global label). */
export function domainStatusLabel(domain: WaitingDomain, status: string): string | undefined {
    return LABEL[domain]?.[status];
}

/** "Menunggu Review · PM / Asisten PM" — for plain text (titles, notifications rendered client-side). */
export function statusWithHolder(label: string, holder: string | null | undefined): string {
    return holder ? `${label} · ${holder}` : label;
}
