import { PageHeader } from '@/Components/shared/PageHeader';
import { Button } from '@/Components/ui/button';
import AppLayout from '@/Layouts/AppLayout';
import type { PageProps, PaginatedData, Task } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';

interface StaffPaymentsIndexProps {
    tasks: PaginatedData<Task>;
}

function formatRupiah(value: string | number) {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(Number(value));
}

/**
 * "Pencatatan upah tukang per task selesai + staff payment list"
 * (.claude/plan/sprint-04.md Jonathan Week 8) — every DONE task with a
 * `rate_per_task` that hasn't been paid yet (see
 * FinanceTransactionController::staffPayments()).
 */
export default function StaffPaymentsIndex({ tasks }: StaffPaymentsIndexProps) {
    const { auth } = usePage<PageProps>().props;
    const canPay = auth.user.role === 'FINANCE' || auth.user.role === 'SUPERADMIN';

    function onPay(task: Task) {
        if (!confirm(`Catat upah task "${task.title}" untuk ${task.assignee?.name}?`)) return;
        router.post(route('finance.staffPayments.pay', { task: task.id }));
    }

    return (
        <AppLayout breadcrumbs={[{ label: 'Finance', routeName: 'finance.dashboard' }, { label: 'Upah Tukang' }]}>
            <Head title="Upah Tukang" />

            <PageHeader
                title="Upah Tukang"
                description="Task DONE dengan rate per task yang belum dibayarkan."
            />

            <div className="overflow-hidden rounded-lg border border-daiku-border">
                <table className="w-full text-sm">
                    <thead className="bg-daiku-yellow-light">
                        <tr>
                            <th className="p-2 text-left font-medium">Task</th>
                            <th className="p-2 text-left font-medium">Tukang</th>
                            <th className="p-2 text-left font-medium">Proyek</th>
                            <th className="p-2 text-left font-medium">Selesai</th>
                            <th className="p-2 text-right font-medium">Upah</th>
                            <th className="w-40 p-2" />
                        </tr>
                    </thead>
                    <tbody>
                        {tasks.data.length === 0 ? (
                            <tr>
                                <td colSpan={6} className="p-6 text-center text-daiku-muted">
                                    Tidak ada upah tukang yang perlu dibayar.
                                </td>
                            </tr>
                        ) : (
                            tasks.data.map((task) => (
                                <tr key={task.id} className="border-t border-daiku-border">
                                    <td className="p-2 font-medium">{task.title}</td>
                                    <td className="p-2 text-daiku-muted">{task.assignee?.name ?? '—'}</td>
                                    <td className="p-2 text-daiku-muted">{task.project?.name ?? '—'}</td>
                                    <td className="p-2 text-daiku-muted">
                                        {task.completed_at ? new Date(task.completed_at).toLocaleDateString('id-ID') : '—'}
                                    </td>
                                    <td className="p-2 text-right font-medium text-daiku-dark">
                                        {formatRupiah(task.rate_per_task ?? 0)}
                                    </td>
                                    <td className="p-2">
                                        {canPay && (
                                            <Button variant="outline" size="sm" onClick={() => onPay(task)}>
                                                Bayar
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
