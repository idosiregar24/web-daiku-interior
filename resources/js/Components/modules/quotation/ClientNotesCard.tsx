import { SectionCard } from '@/Components/shared/SectionCard';
import { Button } from '@/Components/ui/button';
import { Form, FormControl, FormDescription, FormField, FormItem, FormMessage } from '@/Components/ui/form';
import { Textarea } from '@/Components/ui/textarea';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { NotebookText } from 'lucide-react';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors SaveClientNotesRequest.
const schema = z.object({ client_notes: z.string().max(2000, 'Catatan maksimal 2000 karakter.') });

type FormValues = z.infer<typeof schema>;

/**
 * Sprint 15 K4 — the "Catatan" printed on the letter (PDF & client link):
 * one point per line. Empty = the type's default from Pengaturan Situs
 * (shown as placeholder). The Estimator edits it while the RAB is a DRAFT;
 * everyone else reads what the client will see.
 */
export function ClientNotesCard({
    quotationId,
    notes,
    defaultNotes,
    editable,
}: {
    quotationId: number;
    notes: string | null;
    defaultNotes: string;
    editable: boolean;
}) {
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { client_notes: notes ?? '' } });

    useEffect(() => {
        form.reset({ client_notes: notes ?? '' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [notes]);

    function onSubmit(values: FormValues) {
        router.put(route('quotations.clientNotes.update', { quotation: quotationId }), values, {
            preserveScroll: true,
            onError: (errors) => form.setError('client_notes', { message: errors.client_notes ?? errors.status }),
        });
    }

    const shown = (notes?.trim() || defaultNotes).split(/\r?\n/).filter((line) => line.trim() !== '');

    return (
        <SectionCard
            title="Catatan untuk Klien"
            icon={NotebookText}
            description="Dicetak di bagian Catatan surat penawaran & link klien, setelah baris pembayaran."
            className="mt-6"
        >
            {editable ? (
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-3">
                        <FormField
                            control={form.control}
                            name="client_notes"
                            render={({ field }) => (
                                <FormItem>
                                    <FormControl>
                                        <Textarea {...field} rows={4} maxLength={2000} placeholder={defaultNotes} />
                                    </FormControl>
                                    <FormDescription>Satu poin per baris. Kosongkan untuk memakai catatan bawaan (teks abu-abu).</FormDescription>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <div className="flex justify-end">
                            <Button type="submit" size="sm" disabled={!form.formState.isDirty || form.formState.isSubmitting}>
                                Simpan Catatan
                            </Button>
                        </div>
                    </form>
                </Form>
            ) : (
                <ol className="list-decimal space-y-1 pl-5 text-sm text-foreground">
                    {shown.map((line, index) => (
                        <li key={index}>{line}</li>
                    ))}
                    {!notes?.trim() && <p className="-ml-5 pt-1 text-xs text-muted-foreground">Catatan bawaan dari Pengaturan Situs.</p>}
                </ol>
            )}
        </SectionCard>
    );
}
