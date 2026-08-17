import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import type { PaginatedData, QaForm } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

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
        <AppLayout breadcrumbs={[{ label: 'QA' }]}>
            <Head title="QA Form" />

            <PageHeader
                title="QA Form"
                description="Checklist kualitas per milestone — dibuat otomatis saat PM menandai milestone selesai."
            />

            <div className="mb-4">
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

            <div className="overflow-hidden rounded-lg border border-daiku-border">
                <table className="w-full text-sm">
                    <thead className="bg-daiku-yellow-light">
                        <tr>
                            <th className="p-2 text-left font-medium">Proyek</th>
                            <th className="p-2 text-left font-medium">Milestone</th>
                            <th className="p-2 text-left font-medium">Status</th>
                            <th className="p-2 text-left font-medium">Reviewer</th>
                            <th className="p-2 text-left font-medium">Reject Ke-</th>
                        </tr>
                    </thead>
                    <tbody>
                        {qaForms.data.length === 0 ? (
                            <tr>
                                <td colSpan={5} className="p-6 text-center text-daiku-muted">
                                    Belum ada QA Form.
                                </td>
                            </tr>
                        ) : (
                            qaForms.data.map((qaForm) => (
                                <tr key={qaForm.id} className="border-t border-daiku-border">
                                    <td className="p-2 font-medium">
                                        <Link
                                            href={route('qa-forms.show', { qa_form: qaForm.id })}
                                            className="text-daiku-dark hover:underline"
                                        >
                                            {qaForm.project?.name ?? '—'}
                                        </Link>
                                    </td>
                                    <td className="p-2 text-daiku-muted">{qaForm.milestone?.name ?? '—'}</td>
                                    <td className="p-2">
                                        <StatusChip status={qaForm.status} />
                                    </td>
                                    <td className="p-2 text-daiku-muted">{qaForm.reviewer?.name ?? '—'}</td>
                                    <td className="p-2 text-daiku-muted">{qaForm.rejection_count}x</td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
