import { EmptyState } from '@/Components/shared/EmptyState';
import { SectionCard } from '@/Components/shared/SectionCard';
import { Button } from '@/Components/ui/button';
import { Form, FormControl, FormField, FormItem, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { QUOTATION_TYPE_LABEL } from '@/Components/modules/quotation/labels';
import type { DesignDiscussionThread } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Link2, MessagesSquare } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors StoreDesignDiscussionRequest.
const schema = z.object({
    body: z.string().trim().min(1, 'Pesan wajib diisi.').max(5000, 'Pesan maksimal 5000 karakter.'),
    attachment_url: z
        .string()
        .trim()
        .max(2048, 'Link lampiran terlalu panjang.')
        .refine((value) => value === '' || /^https?:\/\/\S+$/i.test(value), 'Lampiran harus berupa link http/https yang valid.'),
});

type FormValues = z.infer<typeof schema>;

interface DesignDiscussionPanelProps {
    thread: DesignDiscussionThread;
    /** On a quotation page: the RAB the new message is about. */
    quotationId?: number;
    className?: string;
}

/**
 * Sprint 12 decision #18 / D6 — the Arsitek ↔ Estimator thread of a
 * design, on the Design page and on every quotation of the same lead.
 * Messages are never edited or removed.
 */
export function DesignDiscussionPanel({ thread, quotationId, className }: DesignDiscussionPanelProps) {
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { body: '', attachment_url: '' } });

    function onSubmit(values: FormValues) {
        router.post(
            route('design.discussions.store', { design: thread.designId }),
            { body: values.body, attachment_url: values.attachment_url || null, quotation_id: quotationId ?? null },
            {
                preserveScroll: true,
                onError: (errors) =>
                    Object.entries(errors).forEach(([field, message]) =>
                        form.setError(field === 'attachment_url' ? 'attachment_url' : 'body', { message }),
                    ),
                onSuccess: () => form.reset({ body: '', attachment_url: '' }),
            },
        );
    }

    return (
        <SectionCard
            title="Diskusi Arsitek ↔ Estimator"
            icon={MessagesSquare}
            description="Desain jadi dasar RAB, dan RAB bisa memantul balik ke arsitek."
            className={className}
        >
            {thread.messages.length === 0 ? (
                <EmptyState title="Belum ada diskusi" description="Mulai percakapan tentang desain atau RAB-nya di sini." />
            ) : (
                <ul className="space-y-3">
                    {thread.messages.map((message) => (
                        <li key={message.id} className="rounded-lg bg-daiku-gray/60 px-3 py-2 text-sm">
                            <div className="flex flex-wrap items-baseline justify-between gap-x-2 text-xs text-daiku-muted">
                                <span>
                                    <span className="font-medium text-daiku-dark">{message.user_name ?? '—'}</span>
                                    {message.quotation &&
                                        ` · ${QUOTATION_TYPE_LABEL[message.quotation.type]} v${message.quotation.version}`}
                                </span>
                                <time dateTime={message.created_at}>{new Date(message.created_at).toLocaleString('id-ID')}</time>
                            </div>
                            <p className="mt-1 whitespace-pre-line text-daiku-dark">{message.body}</p>
                            {message.attachment_url && (
                                <a
                                    href={message.attachment_url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="mt-1 inline-flex items-center gap-1 text-xs underline decoration-daiku-yellow underline-offset-2"
                                >
                                    <Link2 className="size-3.5" />
                                    Lampiran
                                </a>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {thread.canPost && (
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="mt-4 space-y-2">
                        <FormField
                            control={form.control}
                            name="body"
                            render={({ field }) => (
                                <FormItem>
                                    <FormControl>
                                        <Textarea {...field} rows={3} maxLength={5000} placeholder="Tulis pesan…" aria-label="Pesan diskusi" />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-start">
                            <FormField
                                control={form.control}
                                name="attachment_url"
                                render={({ field }) => (
                                    <FormItem className="flex-1">
                                        <FormControl>
                                            <Input {...field} placeholder="Link lampiran (opsional), https://…" aria-label="Link lampiran" />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <Button type="submit" disabled={form.formState.isSubmitting}>
                                Kirim
                            </Button>
                        </div>
                    </form>
                </Form>
            )}
        </SectionCard>
    );
}
