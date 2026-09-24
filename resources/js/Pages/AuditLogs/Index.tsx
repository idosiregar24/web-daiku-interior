import { DatePicker } from '@/Components/shared/DatePicker';
import { PageHeader } from '@/Components/shared/PageHeader';
import { Pagination } from '@/Components/shared/Pagination';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import { formatDateTime } from '@/lib/format';
import type { AuditLog, PaginatedData, User } from '@/types';
import { Head, router } from '@inertiajs/react';
import { format } from 'date-fns';
import { Lock } from 'lucide-react';

interface AuditLogIndexProps {
    logs: PaginatedData<AuditLog>;
    filters: { area?: string; user_id?: string; from?: string; to?: string };
    areas: Record<string, string>;
    actors: Pick<User, 'id' | 'name'>[];
}

/** Human labels for `{area}.{event}` action codes written by AuditLogService callers. */
const ACTION_LABELS: Record<string, string> = {
    'quotation.ceo_approved': 'Quotation disetujui CEO',
    'quotation.ceo_rejected': 'Quotation ditolak CEO',
    'quotation.pm_approved': 'Quotation disetujui PM',
    'quotation.pm_rejected': 'Quotation ditolak PM',
    'quotation.client_approved': 'Quotation diterima klien (deal)',
    'qa.approved': 'QA menyetujui milestone',
    'qa.rejected': 'QA menolak milestone',
    'finance.transaction_created': 'Transaksi dicatat',
    'finance.staff_paid': 'Upah tukang dibayar',
    'finance.termin_paid': 'Termin dibayar',
    'finance.family_fund_expense': 'Penggunaan dana family gathering',
    'overtime.pm_approved': 'Lembur disetujui PM',
    'overtime.pm_rejected': 'Lembur ditolak PM',
    'overtime.finance_approved': 'Lembur disetujui Finance',
    'overtime.finance_rejected': 'Lembur ditolak Finance',
    'penalty.issued': 'Penalti dijatuhkan',
    'user.created': 'User dibuat',
    'user.updated': 'User diubah',
    'analytics.target_set': 'Target pendapatan diatur',
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
        <AppLayout breadcrumbs={[{ label: 'Eksekutif' }, { label: 'Audit Trail' }]}>
            <Head title="Audit Trail" />

            <PageHeader
                title="Audit Trail"
                description="Catatan aksi sensitif: approval quotation, keputusan QA, perubahan finance, penalti, dan akses user."
                actions={
                    <Badge variant="secondary" className="gap-1 border-transparent bg-daiku-gray text-daiku-muted">
                        <Lock className="size-3" />
                        Tidak dapat diubah atau dihapus
                    </Badge>
                }
            />

            <div className="mb-4 flex flex-wrap items-center gap-2">
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
                    placeholder="Dari tanggal"
                    className="w-44"
                />
                <DatePicker
                    value={filters.to ? new Date(filters.to) : undefined}
                    onChange={(date) => applyFilter({ to: date ? format(date, 'yyyy-MM-dd') : undefined })}
                    placeholder="Sampai tanggal"
                    className="w-44"
                />
            </div>

            <div className="overflow-x-auto rounded-lg border border-daiku-border bg-white">
                <table className="w-full text-sm">
                    <thead className="bg-daiku-yellow-light">
                        <tr>
                            <th className="p-2 text-left font-medium whitespace-nowrap">Waktu</th>
                            <th className="p-2 text-left font-medium">Pelaku</th>
                            <th className="p-2 text-left font-medium">Aksi</th>
                            <th className="p-2 text-left font-medium">Data</th>
                            <th className="p-2 text-left font-medium">Perubahan</th>
                            <th className="p-2 text-left font-medium">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        {logs.data.length === 0 ? (
                            <tr>
                                <td colSpan={6} className="p-6 text-center text-daiku-muted">
                                    Belum ada catatan audit.
                                </td>
                            </tr>
                        ) : (
                            logs.data.map((log) => (
                                <tr key={log.id} className="border-t border-daiku-border align-top">
                                    <td className="p-2 whitespace-nowrap text-daiku-muted">{formatDateTime(log.created_at)}</td>
                                    <td className="p-2 font-medium">{log.user?.name ?? (log.user_id ? 'User dihapus' : 'Sistem')}</td>
                                    <td className="p-2">
                                        <p className="font-medium text-daiku-dark">{ACTION_LABELS[log.action] ?? log.action}</p>
                                        <p className="font-mono text-xs text-daiku-muted">{log.action}</p>
                                    </td>
                                    <td className="p-2 whitespace-nowrap text-daiku-muted">
                                        {log.model_type} #{log.model_id}
                                    </td>
                                    <td className="min-w-64 p-2">
                                        <ChangeList log={log} />
                                    </td>
                                    <td className="p-2 font-mono text-xs text-daiku-muted">{log.ip_address ?? '—'}</td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
            <Pagination paginator={logs} />
        </AppLayout>
    );
}
