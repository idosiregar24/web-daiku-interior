import { EmptyState } from '@/Components/shared/EmptyState';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { formatDate, formatRupiah } from '@/lib/format';
import type { ProjectExecution } from '@/types';
import { router } from '@inertiajs/react';
import { HardHat } from 'lucide-react';
import { useState } from 'react';
import { ExecutionDecisionDialog, type ExecutionDecisionTarget } from './ExecutionDecisionDialog';

/**
 * Sprint 24 D4/D5/E3 — Jasa Pengawas: the fee set at the TM, every
 * payment with its turn (PM decides, Finance pays), and "Ajukan Pelunasan"
 * once the project is done. Money only reaches CEO / PM / Finance / the
 * Pengawas (`fee` is null otherwise). Sprint 27 P1c — the DP conditions
 * and "Ajukan DP" live in DpRequirementsCard.
 */
export function SupervisorFeeCard({ projectId, execution }: { projectId: number; execution: ProjectExecution }) {
    const { fee, payments, can } = execution;
    const [deciding, setDeciding] = useState<ExecutionDecisionTarget | null>(null);
    const [busy, setBusy] = useState(false);

    if (!fee) {
        return null;
    }

    function post(routeName: 'projects.supervisor-payments.settlement') {
        router.post(route(routeName, { project: projectId }), {}, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
        });
    }

    return (
        <SectionCard
            title="Jasa Pengawas"
            description={fee ? `Total ${formatRupiah(fee.total_amount)} · DP ${fee.dp_percent}% · dibayar ${formatRupiah(fee.paid)} · sisa ${formatRupiah(fee.remaining)}` : undefined}
            icon={HardHat}
        >
            {payments.length === 0 ? (
                <EmptyState title="Belum ada pengajuan pembayaran pengawas." />
            ) : (
                <ul className="divide-y divide-border">
                    {payments.map((payment) => (
                        <li key={payment.id} className="flex flex-wrap items-center gap-3 py-3">
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-medium text-foreground">{payment.label}</p>
                                <p className="text-xs text-muted-foreground">
                                    {formatRupiah(payment.amount)}
                                    {payment.requested_at && ` · diajukan ${formatDate(payment.requested_at)}`}
                                    {payment.paid_at && ` · dibayar ${formatDate(payment.paid_at)}`}
                                    {payment.status === 'DITOLAK' && payment.decision_note && ` · ${payment.decision_note}`}
                                </p>
                            </div>
                            <StatusChip domain="supervisorPayment" status={payment.status} />
                            {can.decide && payment.status === 'DIAJUKAN' && (
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        setDeciding({
                                            routeName: 'supervisor-payments.decide',
                                            params: { supervisorPayment: payment.id },
                                            title: `Putuskan ${payment.label}`,
                                            description: 'Disetujui → masuk antrean Finance. Untuk DP, persetujuan ini = Start Produksi.',
                                            amount: payment.amount,
                                            amountLabel: 'Nominal disetujui',
                                            amountRequired: true,
                                            remaining: fee ? fee.remaining + payment.amount : null,
                                        })
                                    }
                                >
                                    Putuskan
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {can.requestSettlement && fee && fee.remaining > 0 && (
                <Button variant="outline" className="mt-4" disabled={busy} onClick={() => post('projects.supervisor-payments.settlement')}>
                    Ajukan Pelunasan · {formatRupiah(fee.remaining)}
                </Button>
            )}

            <ExecutionDecisionDialog target={deciding} onOpenChange={(open) => !open && setDeciding(null)} />
        </SectionCard>
    );
}
