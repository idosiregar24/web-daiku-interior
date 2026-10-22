import { DatePicker } from '@/Components/shared/DatePicker';
import { PageHeader } from '@/Components/shared/PageHeader';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { EmptyState } from '@/Components/shared/EmptyState';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import { formatDateTime } from '@/lib/format';
import type { AuditLog, PaginatedData, User } from '@/types';
import { Head, router } from '@inertiajs/react';
import { format } from 'date-fns';
import { Lock, ScrollText } from 'lucide-react';

interface AuditLogIndexProps {
    logs: PaginatedData<AuditLog>;
    filters: { area?: string; user_id?: string; from?: string; to?: string };
    areas: Record<string, string>;
    actors: Pick<User, 'id' | 'name'>[];
}

/** Human labels for `{area}.{event}` action codes written by AuditLogService callers. */
const ACTION_LABELS: Record<string, string> = {
    // Older rows only (CEO approval and "Kirim ke Marketing" were removed in Sprint 23).
    'quotation.ceo_approved': 'Quotation disetujui CEO',
    'quotation.ceo_returned': 'RAB dikembalikan CEO',
    'quotation.pm_approved': 'Quotation disetujui PM',
    'quotation.pm_returned': 'RAB dikembalikan PM / Asisten PM',
    'quotation.sent_to_marketing': 'RAB final dikirim ke Marketing',
    'quotation.sent_to_client': 'RAB dikirim ke klien',
    'quotation.cancelled': 'RAB dibatalkan',
    'quotation.client_approved': 'RAB disetujui klien',
    'quotation.client_rejected': 'Quotation ditolak klien',
    'quotation.client_declined': 'Klien tidak setuju RAB (link)',
    'quotation.client_rejection_forwarded': 'Penolakan klien diteruskan ke Estimator',
    'project.opened': 'Proyek dibuka',
    'project.updated': 'Proyek diubah',
    'project.assistant_pm_changed': 'Asisten PM proyek diganti',
    'project.addendum_added': 'RAB Tambahan masuk ke proyek',
    'task.updated': 'Task diubah (tukang/rate)',
    'task.deleted': 'Task dihapus',
    'task.approved': 'Daily target disetujui',
    'task.returned': 'Daily target dikembalikan ke tukang',
    'task.checked_by_supervisor': 'Daily target dicentang Pengawas',
    'qa.approved': 'QA menyetujui timeline',
    'qa.rejected': 'QA menolak timeline',
    'finance.transaction_created': 'Transaksi dicatat',
    'finance.staff_paid': 'Upah tukang dibayar',
    // Sprint 24 — alur eksekusi proyek.
    'project.phase_changed': 'Tahap proyek berlanjut',
    'working_drawing.assigned': 'Gambar kerja ditugaskan',
    'working_drawing.links_updated': 'Link gambar kerja disimpan',
    'working_drawing.completed': 'Gambar kerja selesai',
    'technical_meeting.saved': 'Technical Meeting disimpan',
    // Sprint 29 — Jasa Pengawas became "Upah Tukang", the weekly report the pengecekan.
    'supervisor_payment.requested': 'Upah tukang diajukan',
    'supervisor_payment.approved': 'Upah tukang disetujui PM',
    'supervisor_payment.rejected': 'Upah tukang ditolak PM',
    'finance.supervisor_paid': 'Upah tukang dibayar ke Pengawas',
    'weekly_report.submitted': 'Pengecekan dikirim',
    'weekly_report.approved': 'Pengecekan disetujui PM',
    'weekly_report.rejected': 'Pengecekan ditolak PM',
    // Sprint 29 Sub 08–12 — Upah Tukang per item RAB, pengecekan %, QA per item.
    'work_item.updated': 'Upah item RAB diubah',
    'work_item.team_lead_assigned': 'Kepala Tim item diterapkan',
    'work_item.progress_recorded': 'Progres % per item dicatat',
    // Sprint 31 Sub 03 — jadwal per item.
    'work_item.scheduled': 'Jadwal item RAB diatur',
    // Sprint 32 — Pembagian Upah per Orang.
    'wage_split.level_updated': 'Bobot tingkatan karir diubah',
    'wage_split.template_saved': 'Template tim tukang disimpan',
    'wage_split.template_deleted': 'Template tim tukang dihapus',
    'wage_split.project_saved': 'Pembagian upah proyek diatur',
    'wage_split.project_cleared': 'Pembagian upah proyek dikosongkan',
    'work_item_qa.passed': 'Item RAB lolos QA',
    'work_item_qa.repair': 'Item RAB perlu perbaikan',
    'site_finding.recorded': 'Temuan finishing dicatat',
    'site_finding.repair_task': 'Task perbaikan dibuat',
    'site_finding.resolved': 'Temuan finishing selesai',
    'finance.termin_paid': 'Termin dibayar',
    'finance.invoice_attached_to_termin': 'Invoice DP dikaitkan ke termin',
    'finance.invoice_issued': 'Invoice diterbitkan',
    'finance.invoice_proof_submitted': 'Bukti bayar invoice dikirim',
    'finance.invoice_payment': 'Pemasukan dari invoice',
    'finance.invoice_verified': 'Pembayaran invoice diverifikasi',
    'finance.invoice_rejected': 'Bukti bayar invoice ditolak',
    'finance.termin_payment': 'Pembayaran termin (DP/pelunasan)',
    'finance.family_fund_expense': 'Penggunaan dana family gathering',
    'finance.penalty_collected': 'Pembayaran penalti dicatat',
    'finance.fund_transferred': 'Pindah dana antar rekening',
    'finance.bank_account_created': 'Rekening bank ditambahkan',
    'finance.opening_balance_changed': 'Saldo awal rekening diubah',
    'finance.allocation_created': 'Alokasi persentase ditambahkan',
    'finance.allocation_updated': 'Alokasi persentase diubah',
    'finance.staff_loan_created': 'Pinjaman tukang dicatat',
    'finance.staff_loan_payment': 'Cicilan pinjaman tukang',
    'finance.supplier_debt_created': 'Hutang supplier dicatat',
    'finance.supplier_debt_paid': 'Pembayaran hutang supplier',
    'finance.asset_installment_paid': 'Cicilan aset dibayar',
    'finance.asset_installment_plan_updated': 'Rencana cicilan aset diubah',
    'finance.salary_paid': 'Gaji karyawan dibayar',
    // Sprint 28 K15 — BudgetRealizationService (realisasi melebihi anggaran → CEO).
    'finance.budget_overrun_requested': 'Pengajuan overrun anggaran',
    'finance.budget_overrun_approved': 'Overrun anggaran disetujui',
    'finance.budget_overrun_rejected': 'Overrun anggaran ditolak',
    'overtime.pm_approved': 'Lembur disetujui PM',
    'overtime.pm_rejected': 'Lembur ditolak PM',
    'overtime.finance_approved': 'Lembur disetujui Finance',
    'overtime.finance_rejected': 'Lembur ditolak Finance',
    'penalty.issued': 'Penalti dijatuhkan',
    'user.created': 'User dibuat',
    'user.updated': 'User diubah',
    'user.identity_changed': 'Username / email diubah',
    // CRM & desain.
    'crm.survey_cancelled': 'Jadwal survey dibatalkan',
    'design.assigned': 'Desain ditugaskan ke arsitek',
    'design.links_updated': 'Link grup WA / desain disimpan',
    'design.client_approved': 'Desain disetujui klien',
    // Logistik & material.
    'logistics.material_registered': 'Material katalog ditambahkan',
    'logistics.material_created_despite_similar': 'Material katalog ditambahkan (ada yang mirip)',
    'logistics.material_merged': 'Material katalog digabung',
    'logistics.material_requested': 'Barang diajukan',
    'logistics.material_request_pm_approved': 'Pengajuan barang disetujui PM',
    'logistics.material_request_pm_rejected': 'Pengajuan barang ditolak PM',
    'logistics.material_request_approved': 'Pengajuan barang disetujui Logistik',
    'logistics.material_request_rejected': 'Pengajuan barang ditolak Logistik',
    'logistics.material_plan_submitted': 'Rencana bahan diajukan ke Logistik',
    'logistics.material_ordered': 'Bahan dipesan',
    'logistics.material_purchased': 'Pembelian bahan dicatat',
    'logistics.material_used': 'Pemakaian bahan dicatat',
    'logistics.material_returned': 'Sisa bahan dibereskan',
    // SDM.
    'hr.division_created': 'Divisi ditambahkan',
    'hr.division_updated': 'Divisi diubah',
    'hr.division_deleted': 'Divisi dihapus',
    'hr.position_created': 'Jabatan ditambahkan',
    'hr.position_updated': 'Jabatan diubah',
    'hr.position_deleted': 'Jabatan dihapus',
    'hr.employee_created': 'Karyawan ditambahkan',
    'hr.employee_updated': 'Data karyawan diubah',
    'hr.discipline_recorded': 'Kedisiplinan / SP dicatat',
    'hr.discipline_voided': 'Catatan kedisiplinan dibatalkan',
    'hr.salary_change_requested': 'Perubahan gaji diajukan',
    'hr.salary_change_approved': 'Perubahan gaji disetujui CEO',
    'hr.salary_change_rejected': 'Perubahan gaji ditolak CEO',
    'hr.salary_change_applied': 'Perubahan gaji diterapkan',
    'hr.kpi_template_saved': 'Template KPI disimpan',
    'hr.kpi_template_toggled': 'Template KPI diaktifkan / dinonaktifkan',
    'hr.kpi_period_opened': 'Periode KPI dibuka',
    'hr.kpi_period_computed': 'Nilai KPI dihitung',
    'hr.kpi_period_closed': 'Periode KPI ditutup',
    'hr.kpi_score_updated': 'Nilai KPI diubah',
    'hr.review_created': 'Evaluasi semester dibuat',
    'hr.review_updated': 'Evaluasi semester diubah',
    'hr.review_submitted': 'Evaluasi semester diajukan',
    'hr.review_approved': 'Evaluasi semester disetujui',
    'hr.review_returned': 'Evaluasi semester dikembalikan',
    'hr.review_acknowledged': 'Evaluasi semester dikonfirmasi karyawan',
    'hr.activity_reviewed': 'Aktivitas harian diperiksa',
    // Situs publik.
    'portfolio.created': 'Portofolio ditambahkan',
    'portfolio.updated': 'Portofolio diubah',
    'portfolio.deleted': 'Portofolio dihapus',
    'portfolio.published': 'Portofolio dipublikasikan',
    'portfolio.unpublished': 'Portofolio ditarik dari situs',
    'portfolio.photo_added': 'Foto portofolio ditambahkan',
    'portfolio.photo_removed': 'Foto portofolio dihapus',
    'portfolio.cover_changed': 'Sampul portofolio diganti',
    'service_page.updated': 'Halaman layanan diubah',
    'service_page.hero_uploaded': 'Gambar halaman layanan diunggah',
    'service_page.hero_removed': 'Gambar halaman layanan dihapus',
    'testimonial.created': 'Testimoni ditambahkan',
    'testimonial.updated': 'Testimoni diubah',
    'testimonial.deleted': 'Testimoni dihapus',
    'analytics.target_set': 'Target pendapatan diatur',
    'settings.updated': 'Pengaturan situs diubah',
    'settings.asset_uploaded': 'Gambar branding diunggah',
    'settings.asset_removed': 'Gambar branding dihapus',
};

function formatValue(value: unknown): string {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'Ya' : 'Tidak';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
}

/** Before → after, one line per field present on either side. */
function ChangeList({ log }: { log: AuditLog }) {
    const keys = [...new Set([...Object.keys(log.old_values ?? {}), ...Object.keys(log.new_values ?? {})])];

    if (keys.length === 0) {
        return <span className="text-daiku-muted">—</span>;
    }

    return (
        <dl className="space-y-0.5 text-xs">
            {keys.map((key) => {
                const before = log.old_values?.[key];
                const after = log.new_values?.[key];
                const hasBefore = log.old_values !== null && key in (log.old_values ?? {});

                return (
                    <div key={key} className="flex flex-wrap gap-x-1.5">
                        <dt className="text-daiku-muted">{key}:</dt>
                        <dd className="break-all text-daiku-dark">
                            {hasBefore && (
                                <>
                                    <span className="text-daiku-muted line-through">{formatValue(before)}</span>
                                    <span className="px-1 text-daiku-muted" aria-label="menjadi">
                                        →
                                    </span>
                                </>
                            )}
                            {formatValue(after)}
                        </dd>
                    </div>
                );
            })}
        </dl>
    );
}

/**
 * PRD §9.4 Audit Trail — CEO read-only. Rows are append-only: no edit or
 * delete control exists here, and the server has no route for either.
 */
export default function AuditLogIndex({ logs, filters, areas, actors }: AuditLogIndexProps) {
    function applyFilter(next: Partial<AuditLogIndexProps['filters']>) {
        router.get(route('audit-logs.index'), { ...filters, ...next }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout>
            <Head title="Audit Trail" />

            <PageHeader
                title="Audit Trail"
                icon={ScrollText}
                description="Catatan aksi sensitif: approval quotation, keputusan QA, perubahan finance, penalti, dan akses user."
                actions={
                    <Badge variant="secondary" className="h-7 gap-1.5 rounded-lg border-transparent bg-daiku-gray px-2.5 text-daiku-muted ring-1 ring-border ring-inset">
                        <Lock className="size-3" />
                        Tidak dapat diubah atau dihapus
                    </Badge>
                }
            />

            <TableCard
                pagination={logs}
                toolbar={
                    <div className="flex flex-wrap items-center gap-2">
                        <Select value={filters.area ?? 'all'} onValueChange={(value) => applyFilter({ area: value === 'all' ? undefined : value })}>
                            <SelectTrigger className="w-40" aria-label="Filter area">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua area</SelectItem>
                                {Object.entries(areas).map(([key, label]) => (
                                    <SelectItem key={key} value={key}>
                                        {label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select
                            value={filters.user_id ? String(filters.user_id) : 'all'}
                            onValueChange={(value) => applyFilter({ user_id: value === 'all' ? undefined : value })}
                        >
                            <SelectTrigger className="w-52" aria-label="Filter pelaku">
                                <SelectValue placeholder="Semua pelaku" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua pelaku</SelectItem>
                                {actors.map((actor) => (
                                    <SelectItem key={actor.id} value={String(actor.id)}>
                                        {actor.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <DatePicker
                            value={filters.from ? new Date(filters.from) : undefined}
                            onChange={(date) => applyFilter({ from: date ? format(date, 'yyyy-MM-dd') : undefined })}
                            clearable
                            placeholder="Dari tanggal"
                            className="w-44"
                        />
                        <DatePicker
                            value={filters.to ? new Date(filters.to) : undefined}
                            onChange={(date) => applyFilter({ to: date ? format(date, 'yyyy-MM-dd') : undefined })}
                            clearable
                            placeholder="Sampai tanggal"
                            className="w-44"
                        />
                    </div>
                }
            >
                <table className="w-full text-sm">
                    <thead className={TABLE_HEAD_CLASS}>
                        <tr>
                            <th className="px-4 py-2.5 text-left font-semibold whitespace-nowrap">Waktu</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Pelaku</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Aksi</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Data</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Perubahan</th>
                            <th className="px-4 py-2.5 text-left font-semibold">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        {logs.data.length === 0 ? (
                            <tr>
                                <td colSpan={6} className="p-0">
                                    <EmptyState title="Belum ada catatan audit." />
                                </td>
                            </tr>
                        ) : (
                            logs.data.map((log) => (
                                <tr key={log.id} className="border-t border-border align-top transition-colors hover:bg-daiku-gray/60">
                                    <td className="px-4 py-3 whitespace-nowrap text-daiku-muted">{formatDateTime(log.created_at)}</td>
                                    <td className="px-4 py-3 font-medium">{log.user?.name ?? (log.user_id ? 'User dihapus' : 'Sistem')}</td>
                                    <td className="px-4 py-3">
                                        <p className="font-medium text-daiku-dark">{ACTION_LABELS[log.action] ?? log.action}</p>
                                        <p className="font-mono text-xs text-daiku-muted">{log.action}</p>
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap text-daiku-muted">
                                        {log.model_type} #{log.model_id}
                                    </td>
                                    <td className="min-w-64 px-4 py-3">
                                        <ChangeList log={log} />
                                    </td>
                                    <td className="px-4 py-3 font-mono text-xs text-daiku-muted">{log.ip_address ?? '—'}</td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </TableCard>
        </AppLayout>
    );
}
