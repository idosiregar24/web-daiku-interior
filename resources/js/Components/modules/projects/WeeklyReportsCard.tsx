import { EmptyState } from '@/Components/shared/EmptyState';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Textarea } from '@/Components/ui/textarea';
import { formatDate, formatRupiah } from '@/lib/format';
import type { ProjectExecution } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { CalendarCheck2, ExternalLink } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { ExecutionDecisionDialog, type ExecutionDecisionTarget } from './ExecutionDecisionDialog';

// Mirrors SubmitWeeklyReportRequest.
const schema = z.object({
    notes: z.string().trim().min(5, 'Catatan pengecekan minimal 5 karakter.').max(5000, 'Catatan maksimal 5000 karakter.'),
    links: z.string().optional(),
});

type FormValues = z.infer<typeof schema>;

/**
 * Sprint 24 D10 — "Pengecekan mingguan (Jumat)": the Pengawas reports the
 * week (notes + links, one per line); the PM approves — optionally with
 * this week's Jasa Pengawas payment, straight to Finance — or rejects with
 * a reason (the Pengawas fixes and re-sends).
 */
export function WeeklyReportsCard({ projectId, execution }: { projectId: number; execution: ProjectExecution }) {
    const { weeklyReports, currentWeek, can, fee } = execution;
    const [deciding, setDeciding] = useState<ExecutionDecisionTarget | null>(null);
    const thisWeek = currentWeek ? weeklyReports.find((report) => report.week_start === currentWeek.week_start) : undefined;
    const canSend = can.submitWeekly && currentWeek && (!thisWeek || thisWeek.status === 'DITOLAK');
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { notes: thisWeek?.notes ?? '', links: (thisWeek?.links ?? []).join('\n') } });

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
                preserveScroll: true,
                onError: (errors) =>
                    Object.entries(errors).forEach(([key, message]) => form.setError(key.startsWith('links') ? 'links' : (key as keyof FormValues), { message })),
            },
        );
    }

    return (
        <SectionCard
            title="Laporan Mingguan"
            description={currentWeek ? `Minggu ke-${currentWeek.week_number} (mulai ${formatDate(currentWeek.week_start)}) — diisi Pengawas tiap Jumat, disetujui PM.` : undefined}
            icon={CalendarCheck2}
        >
            {canSend && (
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
                        <Button type="submit" className="w-full sm:w-auto" disabled={form.formState.isSubmitting}>
                            Kirim Laporan ke PM
                        </Button>
                    </form>
                </Form>
            )}

            {weeklyReports.length === 0 ? (
                <EmptyState title="Belum ada laporan mingguan." />
            ) : (
                <ul className="divide-y divide-border">
                    {weeklyReports.map((report) => (
                        <li key={report.id} className="py-3">
                            <div className="flex flex-wrap items-center gap-3">
                                <p className="min-w-0 flex-1 text-sm font-medium text-foreground">
                                    Minggu ke-{report.week_number} · {formatDate(report.week_start)}
                                    {report.payment_amount !== null && (
                                        <span className="ml-2 text-xs font-normal text-muted-foreground">bayar {formatRupiah(report.payment_amount)}</span>
                                    )}
                                </p>
                                <StatusChip domain="weeklyReport" status={report.status} />
                                {can.decide && report.status === 'DIAJUKAN' && (
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            setDeciding({
                                                routeName: 'weekly-reports.decide',
                                                params: { weeklyReport: report.id },
                                                title: `Laporan Minggu ke-${report.week_number}`,
                                                description: 'Setujui (isi nominal bila minggu ini dibayar — langsung ke antrean Finance) atau tolak dengan alasan.',
                                                amount: null,
                                                amountLabel: 'Bayar jasa pengawas minggu ini',
                                                amountRequired: false,
                                                remaining: fee?.remaining ?? null,
                                            })
                                        }
                                    >
                                        Putuskan
                                    </Button>
                                )}
                            </div>
                            <p className="mt-1 text-sm whitespace-pre-line text-muted-foreground">{report.notes}</p>
                            {report.links.length > 0 && (
                                <div className="mt-1 flex flex-wrap gap-3">
                                    {report.links.map((link) => (
                                        <a key={link} href={link} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-xs text-info-ink underline">
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

