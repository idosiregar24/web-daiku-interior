import { EmptyState } from '@/Components/shared/EmptyState';
import { StatusChip } from '@/Components/shared/StatusChip';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { formatDateTime } from '@/lib/format';
import type { QaForm } from '@/types';
import { Link } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';

/**
 * Sprint 13 #3 — Detail Proyek "QA" tab: one row per milestone QA form
 * (created when the PM marks the milestone done). Status and QA's notes
 * only — never task data (security-standards §2). A rejected milestone is
 * fixed and marked done again from the Milestone tab, which re-queues QA.
 */
export function ProjectQaTab({ qaForms, canOpenForm }: { qaForms: QaForm[]; canOpenForm: boolean }) {
    if (qaForms.length === 0) {
        return (
            <EmptyState
                icon={ShieldCheck}
                className="rounded-xl border border-dashed border-border"
                title="Belum ada milestone yang diajukan ke QA."
                description="Form QA dibuat otomatis saat milestone ditandai selesai."
            />
        );
    }

    return (
        <TableCard>
            <table className="w-full text-sm">
                <thead className={TABLE_HEAD_CLASS}>
                    <tr>
                        <th className="px-4 py-2.5 text-left font-semibold">Milestone</th>
                        <th className="px-4 py-2.5 text-left font-semibold">Status</th>
                        <th className="px-4 py-2.5 text-left font-semibold">Catatan QA</th>
                        <th className="px-4 py-2.5 text-left font-semibold">Reviewer</th>
                        <th className="px-4 py-2.5 text-left font-semibold">Diputuskan</th>
                        <th className="px-4 py-2.5 text-right font-semibold">Ditolak</th>
                    </tr>
                </thead>
                <tbody>
                    {qaForms.map((form) => (
                        <tr key={form.id} className="border-t border-border align-top">
                            <td className="px-4 py-3 font-medium">
                                {canOpenForm ? (
                                    <Link href={route('qa-forms.show', { qa_form: form.id })} className="hover:underline">
                                        {form.milestone?.name ?? '—'}
                                    </Link>
                                ) : (
                                    (form.milestone?.name ?? '—')
                                )}
                            </td>
                            <td className="px-4 py-3">
                                <StatusChip status={form.status} />
                            </td>
                            <td className="max-w-80 px-4 py-3 text-daiku-muted">{form.notes || '—'}</td>
                            <td className="px-4 py-3 text-daiku-muted">{form.reviewer?.name ?? '—'}</td>
                            <td className="px-4 py-3 whitespace-nowrap text-daiku-muted">{form.reviewed_at ? formatDateTime(form.reviewed_at) : '—'}</td>
                            <td className="px-4 py-3 text-right text-daiku-muted tabular-nums">{form.rejection_count}x</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </TableCard>
    );
}
