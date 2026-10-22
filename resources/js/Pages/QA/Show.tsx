import { Notice } from '@/Components/shared/Notice';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import { SectionCard } from '@/Components/shared/SectionCard';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import {
    Form,
    FormControl,
    FormDescription,
    FormField,
    FormItem,
    FormLabel,
    FormMessage,
} from '@/Components/ui/form';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import type { QaForm } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, ClipboardCheck, ShieldCheck, XCircle } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

interface QaShowProps {
    qaForm: QaForm;
    canReview: boolean;
    /** Sprint 28 K10 — open site findings holding this timeline's approval (QaFormService::review's own count). */
    openFindingsCount: number;
}

const checklistItemSchema = z.object({
    label: z.string(),
    passed: z.boolean(),
    note: z.string().nullable(),
});

const schema = z.object({
    checklist_data: z.array(checklistItemSchema),
    notes: z.string().optional(),
});

type FormValues = z.infer<typeof schema>;

/**
 * "QA form page: checklist item list + approve/reject + catatan"
 * (.claude/plan/sprint-04.md Ido Week 7). PRD §4.6 "Catatan Penolakan:
 * Jika QA reject, wajib mengisi catatan perbaikan" — mirrored client-side
 * via zod's `refine` below, server-enforced in QaFormService::review().
 */
export default function QaShow({ qaForm, canReview, openFindingsCount }: QaShowProps) {
    const isDecided = qaForm.status !== 'PENDING';
    const editable = canReview && !isDecided;
    // The server refuses an approval while a finding is open — say so up front instead (rejecting stays possible).
    const approvalBlocked = openFindingsCount > 0;

    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: {
            checklist_data: qaForm.checklist_data.map((item) => ({
                label: item.label,
                passed: item.passed,
                note: item.note ?? '',
            })),
            notes: qaForm.notes ?? '',
        },
    });

    function submitDecision(decision: 'approve' | 'reject') {
        return form.handleSubmit((values) => {
            if (decision === 'reject' && !values.notes) {
                form.setError('notes', { message: 'Catatan perbaikan wajib diisi saat reject.' });
                return;
            }

            const onError = (errors: Record<string, string>) => {
                Object.entries(errors).forEach(([field, message]) => {
                    form.setError(field as keyof FormValues, { message });
                });
            };

            router.put(
                route('qa-forms.update', { qa_form: qaForm.id }),
                { decision, checklist_data: values.checklist_data, notes: values.notes || null },
                { onError },
            );
        });
    }

    return (
        <AppLayout
            breadcrumbs={[
                { label: qaForm.project?.name ?? 'QA Form' },
                { label: qaForm.milestone?.name ?? 'Timeline' },
            ]}
        >
            <Head title={`QA Form — ${qaForm.project?.name ?? ''}`} />

            <PageHeader
                title={`QA Form: ${qaForm.milestone?.name ?? '—'}`}
                icon={ShieldCheck}
                description={
                    <span className="flex flex-wrap items-center gap-2">
                        {qaForm.project?.name && `Proyek: ${qaForm.project.name}`}
                        <StatusChip domain="qa" status={qaForm.status} />
                    </span>
                }
                step={editable ? 'Tugas Anda: periksa tiap item checklist, lalu Setujui atau Tolak QA di bawah.' : undefined}
            />

            {qaForm.rejection_count > 0 && (
                <Notice tone="warning" className="mb-6">
                    Timeline ini sudah ditolak QA {qaForm.rejection_count}x.
                    {qaForm.rejection_count >= 2 && ' CEO sudah diberi notifikasi.'}
                </Notice>
            )}

            {!isDecided && approvalBlocked && (
                <Notice tone="warning" className="mb-6">
                    {openFindingsCount} temuan finishing masih terbuka di timeline ini — QA baru bisa disetujui setelah semuanya
                    selesai.{' '}
                    <Link
                        href={`${route('projects.show', { project: qaForm.project_id, tab: 'execution' })}#temuan`}
                        className="font-medium underline underline-offset-2"
                    >
                        Lihat Temuan
                    </Link>
                </Notice>
            )}

            <SectionCard title="Checklist Kualitas" icon={ClipboardCheck}>
                <Form {...form}>
                    <form className="space-y-4">
                        <div className="divide-y divide-border overflow-hidden rounded-lg border border-border">
                            {form.watch('checklist_data').map((item, index) => (
                                <div key={index} className="flex flex-col gap-2 p-3 transition-colors hover:bg-daiku-gray/50 sm:flex-row sm:items-start">
                                    <FormField
                                        control={form.control}
                                        name={`checklist_data.${index}.passed`}
                                        render={({ field }) => (
                                            <FormItem className="flex flex-1 items-start gap-2 space-y-0">
                                                <FormControl>
                                                    <Checkbox
                                                        checked={field.value}
                                                        onCheckedChange={field.onChange}
                                                        disabled={!editable}
                                                        className="mt-0.5 size-5 sm:size-4"
                                                    />
                                                </FormControl>
                                                <FormLabel className="font-normal text-daiku-dark">{item.label}</FormLabel>
                                            </FormItem>
                                        )}
                                    />
                                    <FormField
                                        control={form.control}
                                        name={`checklist_data.${index}.note`}
                                        render={({ field }) => (
                                            <FormItem className="w-full sm:w-64">
                                                <FormControl>
                                                    <Textarea
                                                        {...field}
                                                        value={field.value ?? ''}
                                                        disabled={!editable}
                                                        rows={1}
                                                        placeholder="Catatan item (opsional)"
                                                    />
                                                </FormControl>
                                            </FormItem>
                                        )}
                                    />
                                </div>
                            ))}
                        </div>

                        <FormField
                            control={form.control}
                            name="notes"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Catatan Keputusan</FormLabel>
                                    {editable && <FormDescription>Wajib diisi bila keputusan Reject.</FormDescription>}
                                    <FormControl>
                                        <Textarea {...field} disabled={!editable} rows={3} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />

                        {editable ? (
                            // Sprint 13 P2 — on a phone the decision bar stays at the bottom of the screen, buttons full width.
                            <div className="sticky bottom-0 z-10 grid grid-cols-2 gap-2 border-t border-border bg-card py-3 sm:static sm:flex sm:justify-end sm:pt-4 sm:pb-0">
                                <Button type="button" variant="destructive" className="h-11 sm:h-8" onClick={submitDecision('reject')}>
                                    <XCircle className="size-4" />
                                    Tolak QA
                                </Button>
                                <Button
                                    type="button"
                                    className="h-11 sm:h-8"
                                    disabled={approvalBlocked}
                                    title={approvalBlocked ? 'Selesaikan temuan finishing yang masih terbuka dulu.' : undefined}
                                    onClick={submitDecision('approve')}
                                >
                                    <CheckCircle2 className="size-4" />
                                    Setujui QA
                                </Button>
                            </div>
                        ) : (
                            <p className="text-sm text-daiku-muted">
                                {isDecided
                                    ? `QA Form ini sudah diputuskan (${qaForm.reviewer?.name ?? '—'}).`
                                    : 'Anda tidak punya akses untuk mereview QA Form ini.'}
                            </p>
                        )}
                    </form>
                </Form>
            </SectionCard>

            <div className="mt-4">
                <Button variant="ghost" size="sm" asChild>
                    <Link href={route('qa-forms.index')}>
                        <ArrowLeft className="size-4" />
                        Kembali ke daftar QA Form
                    </Link>
                </Button>
            </div>
        </AppLayout>
    );
}
