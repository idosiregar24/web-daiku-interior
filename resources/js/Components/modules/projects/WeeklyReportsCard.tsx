import { EmptyState } from '@/Components/shared/EmptyState';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Textarea } from '@/Components/ui/textarea';
import { formatDate, formatRupiah } from '@/lib/format';
import { projectVisit, RELOAD } from '@/lib/projectReload';
import { formatPercent } from '@/lib/workCheck';
import type { ProjectExecution } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { ArrowRight, CalendarCheck2, ExternalLink, Loader2 } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { ExecutionDecisionDialog, type ExecutionDecisionTarget } from './ExecutionDecisionDialog';
import type { ProjectTabTarget } from './projectWaiting';
import { SharesList } from './WorkCheckForm';

// Mirrors SubmitWeeklyReportRequest.
const schema = z.object({
    notes: z.string().trim().min(5, 'Catatan pengecekan minimal 5 karakter.').max(5000, 'Catatan maksimal 5000 karakter.'),
    links: z.string().optional(),
});

type FormValues = z.infer<typeof schema>;
type Report = ProjectExecution['weeklyReports'][number];

/**
 * Sprint 31 — the pengecekan's state, one rule for the Eksekusi card and the
 * Lembar Progres (tab Item RAB): this week's latest report, whether another
 * may be sent (server: `currentWeek.open`), its label, and why not.
 */
export function workCheckState(execution: ProjectExecution) {
    const { weeklyReports, currentWeek, can, workCheck } = execution;
    // The week's latest report (the list is newest first).
    const thisWeek = currentWeek ? weeklyReports.find((report) => report.week_start === currentWeek.week_start) : undefined;
    const weekOpen = Boolean(currentWeek?.open);

    return {
        thisWeek,
        weekOpen,
        canSend: can.submitWeekly && weekOpen,
        showsCheck: workCheck !== null && currentWeek !== null && (can.submitWeekly || can.fillWorkCheck),
        nextLabel: currentWeek ? `Minggu ke-${currentWeek.week_number}${thisWeek && workCheck ? ` · Pengecekan ke-${thisWeek.sequence + 1}` : ''}` : '',
        blockedReason:
            can.submitWeekly && !weekOpen
                ? `Pengecekan ${thisWeek?.label ?? 'minggu ini'} masih menunggu keputusan PM — isian ini tersimpan sebagai draf; kirim setelah PM memutuskan.`
                : null,
        rejectedNote: thisWeek?.status === 'DITOLAK' && can.submitWeekly ? thisWeek.decision_note : null,
        // A new form once this week's report was sent / decided (fresh progress, no draft).
        formKey: `${thisWeek?.id ?? 'new'}-${thisWeek?.status ?? ''}-${weeklyReports.length}`,
    };
}

/**
 * The PM's decision on a pengecekan / weekly report — one dialog set-up for
 * the Eksekusi card and the Lembar Progres (Sprint 31).
 */
export function decisionTargetOf(report: Report, execution: ProjectExecution): ExecutionDecisionTarget {
    const pending = report.payment?.status === 'DIAJUKAN' ? report.payment : null;
    const perItem = execution.usesWorkItems;
    const remaining = execution.fee?.remaining ?? null;

    return {
        routeName: 'weekly-reports.decide',
        params: { weeklyReport: report.id },
        title: perItem ? `Pengecekan ${report.label}` : `Laporan ${report.label}`,
        description: pending
            ? `Pengawas mengajukan ${pending.kind === 'PELUNASAN' ? 'pelunasan' : 'pencairan'} upah ${formatRupiah(pending.amount)}. Setujui (nominal boleh diturunkan, 0 = tidak dicairkan minggu ini) → antrean Finance, atau tolak dengan alasan — persen per item tetap tercatat.`
            : perItem
              ? 'Tidak ada upah yang diajukan minggu ini. Setujui atau tolak dengan alasan.'
              : 'Setujui (isi nominal bila minggu ini dibayar — langsung ke antrean Finance) atau tolak dengan alasan.',
        amount: pending ? pending.amount : null,
        amountLabel: pending ? 'Upah dicairkan' : 'Bayar upah tukang minggu ini',
        amountRequired: pending !== null,
        allowZero: pending !== null,
        showAmount: pending !== null || !perItem,
        remaining: pending ? (remaining ?? 0) + pending.amount : remaining,
        details: perItem ? <ReportDetails report={report} /> : undefined,
    };
}

/**
 * Sprint 24 D10 — "Pengecekan mingguan" (Sprint 29 Sub 02: any day, by Friday): the Pengawas reports the
 * week (notes + links, one per line); the PM approves or rejects with a
 * reason (the Pengawas fixes and re-sends).
 *
 * Sprint 29 Sub 09 — a project with wages per RAB item: the report is the
 * pengecekan % per item (WorkCheckForm) and sending it asks "cair kali ini";
 * the PM's one decision covers the report and that request (amount may be
 * lowered, 0 = not this week). Older projects keep the typed weekly amount.
 */
export function WeeklyReportsCard({
    projectId,
    execution,
    onOpen,
}: {
    projectId: number;
    execution: ProjectExecution;
    /** Sprint 31 Sub 07 — the pengecekan % per item is filled on the Lembar Progres (tab Item RAB). */
    onOpen?: (target: ProjectTabTarget) => void;
}) {
    const { weeklyReports, currentWeek, can, workCheck } = execution;
    const [deciding, setDeciding] = useState<ExecutionDecisionTarget | null>(null);
    const [saving, setSaving] = useState(false);
    const { thisWeek, weekOpen, canSend, showsCheck, nextLabel, blockedReason } = workCheckState(execution);
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: {
            notes: thisWeek?.notes ?? '',
            links: (thisWeek?.links ?? []).join('\n'),
        },
    });

    if (!currentWeek && weeklyReports.length === 0) {
        return null;
    }

    function onSubmit(values: FormValues) {
        router.post(
            route('projects.weekly-reports.store', { project: projectId }),
            {
                notes: values.notes,
                links: (values.links ?? '')
                    .split('\n')
                    .map((line) => line.trim())
                    .filter(Boolean),
            },
            {
                // Sent to the PM — a step, so it keeps the pop-up; reloads the Eksekusi side only.
                ...projectVisit(RELOAD.supervision),
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onError: (errors) =>
                    Object.entries(errors).forEach(([key, message]) =>
                        form.setError(key.startsWith('links') ? 'links' : (key as keyof FormValues), { message }),
                    ),
            },
        );
    }

    function decide(report: Report) {
        setDeciding(decisionTargetOf(report, execution));
    }

    return (
        <SectionCard
            title={workCheck ? 'Pengecekan Mingguan' : 'Laporan Mingguan'}
            description={
                currentWeek
                    ? `Minggu ke-${currentWeek.week_number} (mulai ${formatDate(currentWeek.week_start)}) — diisi Pengawas kapan saja minggu ini, paling lambat Jumat; disetujui PM.${workCheck ? ' Kirim pengecekan = ajukan pencairan upah; boleh lebih dari sekali seminggu setelah pengecekan sebelumnya diputuskan PM.' : ''}`
                    : undefined
            }
            icon={CalendarCheck2}
        >
            {showsCheck && workCheck && currentWeek ? (
                // Sprint 31 Sub 07 — filled on the Lembar Progres, the Excel-like sheet; this card keeps the status and the PM's decisions.
                <div className="mb-6 flex flex-col gap-3 rounded-lg bg-daiku-gray/60 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-sm text-foreground">
                        {weekOpen ? (
                            <>
                                Pengecekan <span className="font-medium">{nextLabel}</span> diisi di <span className="font-medium">Item RAB › Lembar Progres</span>.
                            </>
                        ) : (
                            (blockedReason ?? `Pengecekan ${thisWeek?.label ?? 'minggu ini'} menunggu keputusan PM.`)
                        )}
                    </p>
                    {onOpen && (
                        <Button size="sm" className="w-full sm:w-auto" onClick={() => onOpen({ tab: 'items', view: 'progres' })}>
                            {canSend ? 'Isi Pengecekan' : 'Buka Lembar Progres'}
                            <ArrowRight className="size-4" />
                        </Button>
                    )}
                </div>
            ) : (
                canSend &&
                !workCheck && (
                    <Form {...form}>
                        <form onSubmit={form.handleSubmit(onSubmit)} className="mb-6 space-y-3 rounded-lg bg-daiku-gray/60 p-4">
                            {thisWeek?.status === 'DITOLAK' && (
                                <p className="text-sm text-error-ink">Ditolak PM: {thisWeek.decision_note} — perbaiki lalu kirim ulang.</p>
                            )}
                            <FormField
                                control={form.control}
                                name="notes"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Catatan pengecekan minggu ini</FormLabel>
                                        <FormControl>
                                            <Textarea {...field} rows={4} placeholder="Progres per timeline, kualitas, bahan, kendala tim…" />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="links"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Link foto / dokumen (satu per baris)</FormLabel>
                                        <FormControl>
                                            <Textarea {...field} rows={2} placeholder="https://drive.google.com/…" />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <Button type="submit" className="w-full sm:w-auto" disabled={saving}>
                                {saving && <Loader2 className="size-4 animate-spin" />}
                                Kirim Laporan ke PM
                            </Button>
                        </form>
                    </Form>
                )
            )}

            {weeklyReports.length === 0 ? (
                <EmptyState title="Belum ada laporan mingguan." />
            ) : (
                <ul className="divide-y divide-border">
                    {weeklyReports.map((report) => (
                        <li key={report.id} className="py-3">
                            <div className="flex flex-wrap items-center gap-3">
                                <p className="min-w-0 flex-1 text-sm font-medium text-foreground">
                                    {report.label} · {formatDate(report.submitted_at ?? report.week_start)}
                                    {report.payment_amount !== null && (
                                        <span className="ml-2 text-xs font-normal text-muted-foreground">
                                            {report.payment?.kind === 'PELUNASAN' ? 'pelunasan' : 'upah'} {formatRupiah(report.payment_amount)}
                                            {report.payment &&
                                                report.payment.status !== 'DIAJUKAN' &&
                                                ` · ${report.payment.status === 'DITOLAK' ? 'ditolak' : report.payment.status === 'DIBAYAR' ? 'dibayar' : 'disetujui'}`}
                                        </span>
                                    )}
                                </p>
                                <StatusChip domain="weeklyReport" status={report.status} />
                                {can.decide && report.status === 'DIAJUKAN' && (
                                    <Button size="sm" onClick={() => decide(report)}>
                                        Putuskan
                                    </Button>
                                )}
                            </div>
                            {report.progress.length > 0 && (
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {report.progress
                                        .map((row) => `${row.description ?? 'Item'} ${row.percent > 0 ? '+' : ''}${formatPercent(row.percent)}`)
                                        .join(' · ')}
                                </p>
                            )}
                            <p className="mt-1 text-sm whitespace-pre-line text-muted-foreground">{report.notes}</p>
                            {report.links.length > 0 && (
                                <div className="mt-1 flex flex-wrap gap-3">
                                    {report.links.map((link) => (
                                        <a
                                            key={link}
                                            href={link}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex items-center gap-1 text-xs text-info-ink underline"
                                        >
                                            <ExternalLink className="size-3" />
                                            Lampiran
                                        </a>
                                    ))}
                                </div>
                            )}
                            {report.decision_note && report.status !== 'DIAJUKAN' && (
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {report.decider}: {report.decision_note}
                                </p>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <ExecutionDecisionDialog target={deciding} onOpenChange={(open) => !open && setDeciding(null)} />
        </SectionCard>
    );
}

/** The PM's dialog: the % this pengecekan added and the split of the request per Kepala Tim. */
function ReportDetails({ report }: { report: Report }) {
    const shares = report.payment?.status === 'DIAJUKAN' ? (report.payment.breakdown ?? []) : [];

    if (report.progress.length === 0 && shares.length === 0) return null;

    return (
        <div className="space-y-2 rounded-lg bg-daiku-gray/60 p-3">
            {report.progress.length > 0 && (
                <ul className="space-y-0.5 text-xs text-muted-foreground">
                    {report.progress.map((row, index) => (
                        <li key={index} className="flex justify-between gap-3">
                            <span>
                                {row.description ?? 'Item'}
                                {row.note && ` — ${row.note}`}
                            </span>
                            <span className="font-medium text-foreground tabular-nums">
                                {row.percent > 0 ? '+' : ''}
                                {formatPercent(row.percent)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            {shares.length > 0 && (
                <div>
                    <p className="text-xs font-medium text-muted-foreground">Rincian per Kepala Tim</p>
                    <SharesList shares={shares} />
                </div>
            )}
        </div>
    );
}
