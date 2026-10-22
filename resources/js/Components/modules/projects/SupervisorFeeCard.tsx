import { EmptyState } from '@/Components/shared/EmptyState';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { formatDate, formatRupiah } from '@/lib/format';
import { projectVisit, RELOAD } from '@/lib/projectReload';
import type { ProjectExecution } from '@/types';
import { router } from '@inertiajs/react';
import { HandCoins, HardHat, Loader2 } from 'lucide-react';
import { useState } from 'react';
import { ExecutionDecisionDialog, type ExecutionDecisionTarget } from './ExecutionDecisionDialog';
import { SharesList } from './WorkCheckForm';

type Payment = ProjectExecution['payments'][number];

/** The DP / pelunasan requests that wait on this viewer (PM) — not those decided with a pengecekan. */
export function waitingPayments(execution: ProjectExecution): Payment[] {
    return execution.can.decide ? execution.payments.filter((payment) => payment.status === 'DIAJUKAN' && !payment.via_report) : [];
}

/** The PM's Setujui / Tolak dialog of one request — the card, the page header and Langkah Kerja alike. */
export function paymentDecisionTarget(payment: Payment, execution: ProjectExecution): ExecutionDecisionTarget {
    return {
        routeName: 'supervisor-payments.decide',
        params: { supervisorPayment: payment.id },
        title: `Putuskan ${payment.label}`,
        description:
            payment.kind === 'DP'
                ? 'Setujui → masuk antrean Finance dan Produksi dimulai (Start Produksi). Nominal boleh diturunkan; tolak dengan alasan.'
                : 'Setujui → masuk antrean Finance. Nominal boleh diturunkan; tolak dengan alasan.',
        amount: payment.amount,
        amountLabel: 'Nominal disetujui',
        amountRequired: true,
        remaining: execution.fee ? execution.fee.remaining + payment.amount : null,
    };
}

/**
 * Sprint 34 — "Setujui DP" from the top of Detail Proyek (header, Langkah
 * Kerja): the oldest request waiting on this PM, the same dialog as the card.
 */
export function DecidePaymentButton({ execution, size = 'default', className }: { execution: ProjectExecution; size?: 'default' | 'sm'; className?: string }) {
    const [deciding, setDeciding] = useState<ExecutionDecisionTarget | null>(null);
    const payment = waitingPayments(execution)[0];

    if (!payment) return null;

    return (
        <>
            <Button size={size} className={className} onClick={() => setDeciding(paymentDecisionTarget(payment, execution))}>
                <HandCoins className="size-4" />
                {/* Short — it sits in the page header and a narrow step card; the amount is in the dialog. */}
                {payment.kind === 'DP' ? 'Setujui DP' : 'Setujui Upah'}
            </Button>
            <ExecutionDecisionDialog target={deciding} onOpenChange={(open) => !open && setDeciding(null)} />
        </>
    );
}

/**
 * Sprint 24 D4/D5/E3 — Jasa Pengawas: the fee set at the TM, every
 * payment with its turn (PM decides, Finance pays), and "Ajukan Pelunasan"
 * once the project is done (Sprint 29 Sub 09: with wages per RAB item, once
 * every item is at 100 %). Money only reaches CEO / PM / Finance / the
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

    // Sprint 34 — what waits on this PM is shown big above the list (the list's own button was easy to miss).
    const waiting = waitingPayments(execution);

    function decide(payment: Payment) {
        setDeciding(paymentDecisionTarget(payment, execution));
    }

    function post(routeName: 'projects.supervisor-payments.settlement') {
        // "Ajukan Pelunasan" — a request to the PM: keeps the pop-up, reloads the Eksekusi side only.
        router.post(route(routeName, { project: projectId }), {}, {
            ...projectVisit(RELOAD.supervision),
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
        });
    }

    return (
        <SectionCard
            title="Upah Tukang"
            description={fee ? `Total ${formatRupiah(fee.total_amount)} · DP ${fee.dp_percent}% · dibayar ${formatRupiah(fee.paid)} · sisa ${formatRupiah(fee.remaining)}` : undefined}
            icon={HardHat}
        >
            {waiting.map((payment) => (
                <div
                    key={payment.id}
                    role="status"
                    className="mb-4 flex flex-col gap-3 rounded-xl border-2 border-daiku-yellow bg-daiku-yellow-light p-4 sm:flex-row sm:items-center"
                >
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-daiku-yellow text-daiku-dark">
                        <HandCoins className="size-5" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="font-semibold text-foreground">
                            {payment.kind === 'DP' ? 'DP upah tukang' : 'Pengajuan upah tukang'} {formatRupiah(payment.amount)} menunggu persetujuan Anda
                        </p>
                        <p className="text-sm text-muted-foreground">
                            Diajukan Pengawas{payment.requested_at ? ` ${formatDate(payment.requested_at)}` : ''}
                            {payment.kind === 'DP' ? ' — bila disetujui, Produksi dimulai dan tukang bisa mengerjakan daily target.' : ' — bila disetujui, masuk antrean Finance.'}
                        </p>
                        {payment.breakdown && payment.breakdown.length > 1 && <SharesList shares={payment.breakdown} className="mt-1 max-w-xs" />}
                    </div>
                    <Button className="w-full sm:w-auto" onClick={() => decide(payment)}>
                        Setujui / Tolak
                    </Button>
                </div>
            ))}

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
                                {/* Sprint 29 Sub 09 — split per Kepala Tim (projects with wages per item). */}
                                {payment.breakdown && payment.breakdown.length > 1 && <SharesList shares={payment.breakdown} className="mt-1 max-w-xs" />}
                                {payment.via_report && payment.status === 'DIAJUKAN' && (
                                    <p className="mt-0.5 text-xs text-muted-foreground">Diputuskan bersama pengecekan mingguannya.</p>
                                )}
                            </div>
                            <StatusChip domain="supervisorPayment" status={payment.status} />
                            {can.decide && payment.status === 'DIAJUKAN' && !payment.via_report && (
                                <Button size="sm" variant="outline" onClick={() => decide(payment)}>
                                    Putuskan
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {can.requestSettlement && fee && fee.remaining > 0 && (
                <Button variant="outline" className="mt-4" disabled={busy} onClick={() => post('projects.supervisor-payments.settlement')}>
                    {busy && <Loader2 className="size-4 animate-spin" />}
                    Ajukan Pelunasan · {formatRupiah(fee.remaining)}
                </Button>
            )}

            <ExecutionDecisionDialog target={deciding} onOpenChange={(open) => !open && setDeciding(null)} />
        </SectionCard>
    );
}
