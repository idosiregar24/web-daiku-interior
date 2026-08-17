import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import {
    Form,
    FormControl,
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
import { CheckCircle2, XCircle } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

interface QaShowProps {
    qaForm: QaForm;
    canReview: boolean;
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
export default function QaShow({ qaForm, canReview }: QaShowProps) {
    const isDecided = qaForm.status !== 'PENDING';
    const editable = canReview && !isDecided;

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
                { label: 'QA', routeName: 'qa-forms.index' },
                { label: qaForm.project?.name ?? 'QA Form' },
            ]}
        >
            <Head title={`QA Form — ${qaForm.project?.name ?? ''}`} />

            <PageHeader
                title={`QA Form: ${qaForm.milestone?.name ?? '—'}`}
                description={qaForm.project?.name ? `Proyek: ${qaForm.project.name}` : undefined}
                actions={<StatusChip status={qaForm.status} />}
            />

            {qaForm.rejection_count > 0 && (
                <p className="mb-4 rounded-lg border border-warning/30 bg-warning/10 p-3 text-sm text-warning">
                    Milestone ini sudah ditolak QA {qaForm.rejection_count}x.
                    {qaForm.rejection_count >= 2 && ' CEO sudah diberi notifikasi.'}
                </p>
            )}

            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Checklist Kualitas</CardTitle>
                </CardHeader>
                <CardContent>
                    <Form {...form}>
                        <form className="space-y-4">
                            <div className="divide-y divide-daiku-border rounded-lg border border-daiku-border">
                                {form.watch('checklist_data').map((item, index) => (
                                    <div key={index} className="flex flex-col gap-2 p-3 sm:flex-row sm:items-start">
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
                                                            className="mt-0.5"
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
                                        <FormLabel>Catatan Keputusan {editable && '(wajib jika reject)'}</FormLabel>
                                        <FormControl>
                                            <Textarea {...field} disabled={!editable} rows={3} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />

                            {editable ? (
                                <div className="flex justify-end gap-2">
                                    <Button type="button" variant="destructive" onClick={submitDecision('reject')}>
                                        <XCircle className="size-4" />
                                        Reject
                                    </Button>
                                    <Button type="button" onClick={submitDecision('approve')}>
                                        <CheckCircle2 className="size-4" />
                                        Approve
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
                </CardContent>
            </Card>

            <div className="mt-4">
                <Link href={route('qa-forms.index')} className="text-sm text-daiku-muted hover:underline">
                    ← Kembali ke daftar QA Form
                </Link>
            </div>
        </AppLayout>
    );
}
