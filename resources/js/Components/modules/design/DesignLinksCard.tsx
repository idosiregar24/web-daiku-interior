import { SectionCard } from '@/Components/shared/SectionCard';
import { Button } from '@/Components/ui/button';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { formatDateTime } from '@/lib/format';
import type { Design } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { ExternalLink, Link2, MessageCircle, Plus, Trash2 } from 'lucide-react';
import { useEffect, useMemo } from 'react';
import { type FieldPath, useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';

/** Mirrors UpdateDesignLinksRequest::WHATSAPP_HOSTS. */
const WHATSAPP_HOSTS = ['wa.me', 'chat.whatsapp.com'];

function parseHttpUrl(value: string): URL | null {
    try {
        const url = new URL(value);

        return url.protocol === 'http:' || url.protocol === 'https:' ? url : null;
    } catch {
        return null;
    }
}

export function isWhatsappGroupUrl(value: string): boolean {
    const url = parseHttpUrl(value);

    return url !== null && WHATSAPP_HOSTS.includes(url.hostname.toLowerCase());
}

/**
 * Mirrors UpdateDesignLinksRequest (+ DesignService::updateLinks()'s "an
 * approved design keeps a link"). Empty design-link rows are dropped on
 * submit, as the server would refuse them.
 */
function buildSchema(clientAcc: boolean) {
    return z
        .object({
            whatsapp_group_url: z
                .string()
                .trim()
                .max(500, 'Link grup WhatsApp maksimal 500 karakter.')
                .refine((value) => value === '' || isWhatsappGroupUrl(value), 'Link grup WhatsApp harus dari chat.whatsapp.com atau wa.me.'),
            design_urls: z
                .array(
                    z.object({
                        value: z
                            .string()
                            .trim()
                            .max(2048, 'Link desain maksimal 2048 karakter.')
                            .refine((value) => value === '' || parseHttpUrl(value) !== null, 'Link desain harus berupa URL yang valid (http/https).'),
                    }),
                )
                .max(20, 'Maksimal 20 link desain.'),
        })
        .superRefine((values, ctx) => {
            if (clientAcc && values.design_urls.every((url) => url.value.trim() === '')) {
                ctx.addIssue({
                    code: 'custom',
                    path: ['design_urls'],
                    message: 'Desain yang sudah disetujui klien harus tetap punya minimal satu link desain.',
                });
            }
        });
}

type FormValues = z.infer<ReturnType<typeof buildSchema>>;

type LinkedDesign = Pick<Design, 'id' | 'whatsapp_group_url' | 'design_urls' | 'client_acc' | 'links_updated_at' | 'links_updater' | 'updated_at'>;

function toFormValues(design: LinkedDesign): FormValues {
    return {
        whatsapp_group_url: design.whatsapp_group_url ?? '',
        design_urls: (design.design_urls ?? []).map((value) => ({ value })),
    };
}

interface DesignLinksCardProps {
    design: LinkedDesign;
    /** DesignPolicy::manageLinks() — the lead's Marketing or the design team, once assigned. */
    canEdit: boolean;
    /** Still waiting for payment / assignment: nothing to save yet. */
    locked: boolean;
    className?: string;
}

/**
 * Sprint 23 K18/K19b — the "Link Desain" card of a design born from a RAB
 * Jasa Desain. Everything with the client runs in a WhatsApp group; the
 * system keeps only the group's link and the design links (Drive / Figma),
 * plus who saved them last. "Buka Grup WA" sits in the page header (K4b);
 * this card keeps its own Simpan button.
 */
export function DesignLinksCard({ design, canEdit, locked, className }: DesignLinksCardProps) {
    const schema = useMemo(() => buildSchema(design.client_acc), [design.client_acc]);
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: toFormValues(design) });
    const { fields, append, remove } = useFieldArray({ control: form.control, name: 'design_urls' });

    // The same design changed on the server (saved here or by someone else):
    // take the new values, keep what this user is still typing.
    useEffect(() => {
        form.reset(toFormValues(design), { keepDirtyValues: true });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [design.id, design.updated_at]);

    function onSubmit(values: FormValues) {
        const payload = {
            whatsapp_group_url: values.whatsapp_group_url.trim() || null,
            design_urls: values.design_urls.map((url) => url.value.trim()).filter((url) => url !== ''),
        };

        router.put(route('design.links.update', { design: design.id }), payload, {
            preserveScroll: true,
            // What the server now holds (blank rows dropped) — the form is clean again.
            onSuccess: () => form.reset(toFormValues({ ...design, ...payload })),
            onError: (errors: Record<string, string>) =>
                Object.entries(errors).forEach(([field, message]) => {
                    // Server keys design_urls.N map onto the field array's `.value` inputs.
                    const [root, index] = field.split('.');
                    const path = root === 'design_urls' && index !== undefined ? `design_urls.${index}.value` : field;
                    form.setError(path as FieldPath<FormValues>, { message });
                }),
        });
    }

    const savedBy = design.links_updated_at ? (
        <span>
            Terakhir disimpan oleh <span className="font-medium text-foreground">{design.links_updater?.name ?? '—'}</span>,{' '}
            {formatDateTime(design.links_updated_at)}
        </span>
    ) : (
        'Belum pernah disimpan.'
    );
    const linksError = form.formState.errors.design_urls?.message ?? form.formState.errors.design_urls?.root?.message;

    return (
        <SectionCard
            title="Link Desain"
            icon={Link2}
            description="Semua komunikasi dengan klien (revisi, persetujuan) berjalan di grup WhatsApp — sistem hanya menyimpan link grup dan link desain."
            footer={locked ? undefined : <p className="text-xs text-muted-foreground">{savedBy}</p>}
            className={className}
        >
            {locked ? (
                <p className="text-sm text-muted-foreground">Link bisa diisi setelah Kepala Desain menugaskan arsitek.</p>
            ) : canEdit ? (
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="whatsapp_group_url"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Link Grup WhatsApp</FormLabel>
                                    <FormControl>
                                        <Input {...field} inputMode="url" placeholder="https://chat.whatsapp.com/…" maxLength={500} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />

                        <div>
                            <div className="mb-2 flex items-center justify-between gap-2">
                                <FormLabel>Link Desain (Drive / Figma)</FormLabel>
                                <Button type="button" variant="outline" size="sm" onClick={() => append({ value: '' })} disabled={fields.length >= 20}>
                                    <Plus className="size-4" />
                                    Tambah Link
                                </Button>
                            </div>
                            {fields.length === 0 ? (
                                <p className="text-sm text-muted-foreground">Belum ada link desain.</p>
                            ) : (
                                <div className="space-y-2">
                                    {fields.map((item, index) => (
                                        <div key={item.id} className="flex items-start gap-2">
                                            <FormField
                                                control={form.control}
                                                name={`design_urls.${index}.value`}
                                                render={({ field }) => (
                                                    <FormItem className="flex-1">
                                                        <FormControl>
                                                            <Input
                                                                {...field}
                                                                inputMode="url"
                                                                placeholder="https://…"
                                                                aria-label={`Link desain ${index + 1}`}
                                                            />
                                                        </FormControl>
                                                        <FormMessage />
                                                    </FormItem>
                                                )}
                                            />
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-sm"
                                                className="mt-0.5"
                                                aria-label={`Hapus link desain ${index + 1}`}
                                                onClick={() => remove(index)}
                                            >
                                                <Trash2 className="size-4 text-error-ink" />
                                            </Button>
                                        </div>
                                    ))}
                                </div>
                            )}
                            {linksError && <p className="mt-2 text-sm text-destructive">{linksError}</p>}
                        </div>

                        <Button type="submit" disabled={form.formState.isSubmitting || !form.formState.isDirty}>
                            Simpan Link
                        </Button>
                    </form>
                </Form>
            ) : (
                <ReadOnlyLinks design={design} />
            )}
        </SectionCard>
    );
}

function ReadOnlyLinks({ design }: { design: LinkedDesign }) {
    const urls = design.design_urls ?? [];

    return (
        <div className="space-y-4 text-sm">
            <div>
                <p className="text-xs text-muted-foreground">Grup WhatsApp</p>
                {design.whatsapp_group_url ? (
                    <a
                        href={design.whatsapp_group_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center gap-1 font-medium break-all text-foreground underline decoration-daiku-yellow underline-offset-2"
                    >
                        <MessageCircle className="size-4 shrink-0" aria-hidden />
                        {design.whatsapp_group_url}
                    </a>
                ) : (
                    <p className="text-muted-foreground">Belum ada.</p>
                )}
            </div>
            <div>
                <p className="text-xs text-muted-foreground">Link Desain</p>
                {urls.length === 0 ? (
                    <p className="text-muted-foreground">Belum ada link desain.</p>
                ) : (
                    <ul className="mt-0.5 space-y-1">
                        {urls.map((url) => (
                            <li key={url}>
                                <a
                                    href={url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex items-center gap-1 break-all text-foreground underline decoration-daiku-yellow underline-offset-2"
                                >
                                    <ExternalLink className="size-3.5 shrink-0" aria-hidden />
                                    {url}
                                </a>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
