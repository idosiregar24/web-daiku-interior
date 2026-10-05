import { EmptyState } from '@/Components/shared/EmptyState';
import { StatusChip } from '@/Components/shared/StatusChip';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { Button } from '@/Components/ui/button';
import { formatDate, formatRupiah } from '@/lib/format';
import type { OvertimeRequest } from '@/types';
import { Link } from '@inertiajs/react';
import { ArrowRight, Clock } from 'lucide-react';

/**
 * Sprint 13 #3 — Detail Proyek "Lembur" tab: this project's overtime
 * requests. Read-only here; PM and Finance decide them on the Lembur page
 * (the link is offered only to roles that may open it).
 */
export function ProjectOvertimeTab({ requests, canOpenList }: { requests: OvertimeRequest[]; canOpenList: boolean }) {
    const waiting = requests.filter((request) => request.status === 'PENDING' || request.status === 'PENDING_FINANCE').length;

    return (
        <div>
            {canOpenList && waiting > 0 && (
                <div className="mb-4 flex justify-end">
                    <Button size="sm" variant="outline" asChild>
                        <Link href={route('overtime.index')}>
                            {waiting} menunggu keputusan — buka halaman Lembur
                            <ArrowRight className="size-3.5" />
                        </Link>
                    </Button>
                </div>
            )}

            {requests.length === 0 ? (
                <EmptyState icon={Clock} className="rounded-xl border border-dashed border-border" title="Belum ada pengajuan lembur di proyek ini." />
            ) : (
                <TableCard>
                    <table className="w-full text-sm">
                        <thead className={TABLE_HEAD_CLASS}>
                            <tr>
                                <th className="px-4 py-2.5 text-left font-semibold">Tanggal</th>
                                <th className="px-4 py-2.5 text-left font-semibold">Tukang</th>
                                <th className="px-4 py-2.5 text-right font-semibold">Jam</th>
                                <th className="px-4 py-2.5 text-right font-semibold">Total</th>
                                <th className="px-4 py-2.5 text-left font-semibold">Alasan</th>
                                <th className="px-4 py-2.5 text-left font-semibold">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {requests.map((request) => (
                                <tr key={request.id} className="border-t border-border align-top">
                                    <td className="px-4 py-3 whitespace-nowrap">{formatDate(request.work_date)}</td>
                                    <td className="px-4 py-3 font-medium">{request.staff?.name ?? '—'}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{Number(request.hours)}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{formatRupiah(request.total_amount)}</td>
                                    <td className="max-w-80 px-4 py-3 text-daiku-muted">
                                        {request.reason}
                                        {request.reject_note && <span className="mt-1 block text-error-ink">Ditolak: {request.reject_note}</span>}
                                    </td>
                                    <td className="px-4 py-3">
                                        <StatusChip status={request.status} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </TableCard>
            )}
        </div>
    );
}
