import { EmptyState } from '@/Components/shared/EmptyState';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import type { PaginatedData, QaForm } from '@/types';
import { ProjectLink } from '@/Components/modules/projects/ProjectLink';
import { Head, Link, router } from '@inertiajs/react';
import { BarChart3, ShieldCheck } from 'lucide-react';
import { DashboardLinkButton } from '@/Components/modules/dashboards/DashboardLinkButton';

interface QaIndexProps {
    qaForms: PaginatedData<QaForm>;
    filters: { status?: string };
}

/** PRD §4.6 / §7.1 "QA Form" row — CEO/PM read, QA CRUD (review). */
export default function QaIndex({ qaForms, filters }: QaIndexProps) {
    function applyFilter(next: Partial<typeof filters>) {
        router.get(route('qa-forms.index'), { ...filters, ...next }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout>
            <Head title="QA Form" />

            <PageHeader
                title="QA Form"
                icon={ShieldCheck}
                description="Checklist kualitas per milestone — dibuat otomatis saat PM menandai milestone selesai."
                actions={<DashboardLinkButton routeName="qa-forms.dashboard" label="Dashboard QA" icon={BarChart3} roles={['CEO', 'QA']} />}
            />

            <TableCard
                pagination={qaForms}
                toolbar={
                    <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                        <Select
                            value={filters.status ?? 'all'}
                            onValueChange={(value) => applyFilter({ status: value === 'all' ? undefined : value })}
                        >
                            <SelectTrigger className="sm:w-56">
                                <SelectValue placeholder="Semua status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua status</SelectItem>
                                <SelectItem value="PENDING">Menunggu Review</SelectItem>
                                <SelectItem value="APPROVED">Disetujui</SelectItem>
                                <SelectItem value="REJECTED">Ditolak</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                }
            >
                {/* Sprint 13 P2 — QA on a phone: one card per form, the whole card opens it. */}
                <ul className="divide-y divide-border md:hidden">
                    {qaForms.data.length === 0 ? (
                        <li>
                            <EmptyState title="Belum ada QA Form." />
                        </li>
                    ) : (
                        qaForms.data.map((qaForm) => (
                            <li key={qaForm.id}>
                                <Link
                                    href={route('qa-forms.show', { qa_form: qaForm.id })}
                                    className="flex items-start justify-between gap-3 px-4 py-3 active:bg-daiku-yellow-light/60"
                                >
                                    <span className="min-w-0">
                                        <span className="block font-medium text-foreground">{qaForm.milestone?.name ?? 'Form QA'}</span>
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {qaForm.project?.name ?? '—'}
                                            {qaForm.rejection_count > 0 && ` · ditolak ${qaForm.rejection_count}x`}
                                        </span>
                                    </span>
                                    <StatusChip status={qaForm.status} />
                                </Link>
                            </li>
                        ))
                    )}
                </ul>

                <table className="hidden w-full text-sm md:table">
                    <thead className={TABLE_HEAD_CLASS}>
                        <tr>
                            <th className="px-4 py-2.5 text-left font-semibold">Proyek</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Milestone</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Status</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Reviewer</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Reject Ke-</th>
                        </tr>
                    </thead>
                    <tbody>
                        {qaForms.data.length === 0 ? (
                            <tr>
                                <td colSpan={5} className="p-0">
                                    <EmptyState title="Belum ada QA Form." />
                                </td>
                            </tr>
                        ) : (
                            qaForms.data.map((qaForm) => (
                                <tr key={qaForm.id} className="border-t border-border transition-colors hover:bg-daiku-gray/60">
                                    <td className="px-4 py-3 font-medium">
                                        <ProjectLink project={qaForm.project} tab="qa" />
                                    </td>
                                    {/* The milestone opens its QA form — the reviewer's working page. */}
                                    <td className="px-4 py-3">
                                        <Link
                                            href={route('qa-forms.show', { qa_form: qaForm.id })}
                                            className="font-medium text-foreground hover:underline"
                                        >
                                            {qaForm.milestone?.name ?? 'Buka form QA'}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3">
                                        <StatusChip status={qaForm.status} />
                                    </td>
                                    <td className="px-4 py-3 text-daiku-muted">{qaForm.reviewer?.name ?? '—'}</td>
                                    <td className="px-4 py-3 text-daiku-muted">{qaForm.rejection_count}x</td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </TableCard>
        </AppLayout>
    );
}
