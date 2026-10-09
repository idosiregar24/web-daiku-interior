import { CharCounter } from '@/Components/modules/settings/CharCounter';
import { optionalInt, portfolioBasePayload, portfolioBaseSchema } from '@/Components/modules/settings/companyProfileSchema';
import { PortfolioBaseFields, PublishChip } from '@/Components/modules/settings/PortfolioFields';
import { PortfolioPhotoManager } from '@/Components/modules/settings/PortfolioPhotoManager';
import { ModuleTabs } from '@/Components/shared/ModuleTabs';
import { PageHeader } from '@/Components/shared/PageHeader';
import { SectionCard } from '@/Components/shared/SectionCard';
import { Button } from '@/Components/ui/button';
import { Form, FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Switch } from '@/Components/ui/switch';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { CityOption, PortfolioItemDetail, PortfolioPhoto, ProjectTypeOption } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Circle, ExternalLink, FileText, FolderKanban, GalleryHorizontalEnd, Globe, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

interface PortfolioEditProps {
    item: PortfolioItemDetail;
    photos: PortfolioPhoto[];
    projectTypes: ProjectTypeOption[];
    cities: CityOption[];
    maxFiles: number;
}

// Mirrors UpdatePortfolioItemRequest (= StorePortfolioItemRequest + sort_order).
const schema = portfolioBaseSchema.extend({
    summary: z.string().max(300, 'Ringkasan maksimal 300 karakter.'),
    description: z.string().max(5000, 'Cerita proyek maksimal 5000 karakter.'),
    client_consent: z.boolean(),
    sort_order: optionalInt(0, 100000, {
        integer: 'Urutan harus berupa angka.',
        min: 'Urutan minimal 0.',
        max: 'Urutan maksimal 100000.',
    }),
});

type FormValues = z.infer<typeof schema>;

function toValues(item: PortfolioItemDetail): FormValues {
    return {
        title: item.title,
        project_type: item.project_type,
        city_id: item.city_id?.toString() ?? '',
        location_label: item.location_label ?? '',
        year: item.year?.toString() ?? '',
        summary: item.summary ?? '',
        description: item.description ?? '',
        client_consent: item.client_consent,
        sort_order: item.sort_order.toString(),
    };
}

/** One line of the "Syarat terbit" checklist. */
function Requirement({ met, children }: { met: boolean; children: string }) {
    const Icon = met ? CheckCircle2 : Circle;

    return (
        <li className={cn('flex items-start gap-2 text-sm', met ? 'text-foreground' : 'text-muted-foreground')}>
            <Icon className={cn('mt-0.5 size-4 shrink-0', met ? 'text-success-ink' : 'text-daiku-muted')} aria-hidden />
            <span>{children}</span>
        </li>
    );
}

/**
 * Sprint 20 Sub 04 — one portfolio item: its text, the client's consent,
 * its photos, and putting it on (or taking it off) the public site.
 * Publishing needs the saved consent and at least one photo
 * (PortfolioService::publish() checks both again).
 */
export default function PortfolioEdit({ item, photos, projectTypes, cities, maxFiles }: PortfolioEditProps) {
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: toValues(item) });
    const [summary, description] = form.watch(['summary', 'description']);
    const dirty = form.formState.isDirty;
    const [publishing, setPublishing] = useState(false);
    const [publishErrors, setPublishErrors] = useState<string[]>([]);

    const hasPhotos = photos.length > 0;
    const ready = item.client_consent && hasPhotos;
    const typeLabel = projectTypes.find((type) => type.value === item.project_type)?.label ?? item.project_type;

    function onSubmit(values: FormValues) {
        router.put(
            route('settings.portfolio.update', { portfolio: item.id }),
            {
                ...portfolioBasePayload(values),
                summary: values.summary.trim() || null,
                description: values.description.trim() || null,
                client_consent: values.client_consent,
                // Empty = keep the current position; the column is never null.
                ...(values.sort_order.trim() === '' ? {} : { sort_order: Number(values.sort_order) }),
            },
            {
                preserveScroll: true,
                onSuccess: (page) => form.reset(toValues(page.props.item as PortfolioItemDetail)),
                onError: (errors) =>
                    Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof FormValues, { message })),
            },
        );
    }

    function togglePublish() {
        const name = item.is_published ? 'settings.portfolio.unpublish' : 'settings.portfolio.publish';

        router.patch(
            route(name, { portfolio: item.id }),
            {},
            {
                preserveScroll: true,
                onStart: () => {
                    setPublishing(true);
                    setPublishErrors([]);
                },
                // client_consent / photos — the server's own check of the same rules.
                onError: (errors) => setPublishErrors(Object.values(errors)),
                onFinish: () => setPublishing(false),
            },
        );
    }

    function destroy() {
        const where = item.is_published ? ' Halamannya di situs ikut hilang.' : '';
        if (!confirm(`Hapus portofolio "${item.title}" beserta ${photos.length} fotonya? Tidak bisa dibatalkan.${where}`)) return;

        router.delete(route('settings.portfolio.destroy', { portfolio: item.id }));
    }

    const blockedReason = item.is_published
        ? null
        : dirty
          ? 'Simpan perubahan dulu.'
          : !item.client_consent
            ? 'Centang izin klien lalu simpan.'
            : !hasPhotos
              ? 'Unggah minimal satu foto.'
              : null;

    return (
        <AppLayout breadcrumbs={[{ label: item.title }]}>
            <Head title={item.title} />

            <PageHeader
                title={item.title}
                icon={GalleryHorizontalEnd}
                description={`${typeLabel}${item.year ? ` · ${item.year}` : ''}`}
                actions={<PublishChip published={item.is_published} />}
            />

            <ModuleTabs />

            <div className="grid gap-6 lg:grid-cols-3">
                <SectionCard
                    title="Terbit di Situs"
                    icon={Globe}
                    className="h-fit lg:sticky lg:top-20 lg:col-start-3 lg:row-start-1"
                    footer={
                        <Button type="button" variant="ghost" size="sm" onClick={destroy} className="text-error-ink hover:text-error-ink">
                            <Trash2 className="size-3.5" />
                            Hapus Portofolio
                        </Button>
                    }
                >
                    <div className="space-y-4">
                        {item.is_published ? (
                            <p className="text-sm text-muted-foreground">
                                Tampil di situs sejak {formatDate(item.published_at)}: di daftar Portofolio dan halaman layanan sejenis.
                            </p>
                        ) : (
                            <ul className="space-y-2">
                                <Requirement met={item.client_consent}>Klien setuju proyeknya ditampilkan</Requirement>
                                <Requirement met={hasPhotos}>Minimal satu foto</Requirement>
                            </ul>
                        )}

                        <div className="space-y-1.5">
                            <Button
                                type="button"
                                className="w-full"
                                variant={item.is_published ? 'outline' : 'default'}
                                disabled={publishing || (!item.is_published && (!ready || dirty))}
                                onClick={togglePublish}
                            >
                                {item.is_published ? 'Tarik dari Situs' : 'Terbitkan'}
                            </Button>
                            {blockedReason && <p className="text-xs text-muted-foreground">{blockedReason}</p>}
                            {item.is_published && (
                                <p className="text-xs text-muted-foreground">Ditarik = kembali jadi draf; halamannya tidak bisa dibuka pengunjung.</p>
                            )}
                            {publishErrors.map((error) => (
                                <p key={error} className="text-xs text-error-ink">
                                    {error}
                                </p>
                            ))}
                        </div>

                        <div className="space-y-1 border-t border-border pt-3 text-xs text-muted-foreground">
                            <p className="font-medium text-foreground">Alamat halaman</p>
                            {item.is_published ? (
                                <a href={item.public_url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 break-all text-info-ink hover:underline">
                                    {item.public_url}
                                    <ExternalLink className="size-3 shrink-0" />
                                </a>
                            ) : (
                                <p className="break-all">{item.public_url}</p>
                            )}
                            <p>
                                {item.published_at
                                    ? 'Tetap sejak pertama terbit, meski judul diubah.'
                                    : 'Mengikuti judul dan kota sampai pertama kali terbit, lalu tetap.'}
                            </p>
                        </div>

                        {item.project && (
                            <div className="space-y-1 border-t border-border pt-3 text-xs text-muted-foreground">
                                <p className="font-medium text-foreground">Dari proyek</p>
                                <Link href={route('projects.show', { project: item.project.id })} className="inline-flex items-center gap-1 text-info-ink hover:underline">
                                    <FolderKanban className="size-3" />
                                    {item.project.name}
                                </Link>
                                <p>Hanya terlihat di sistem — nama klien, alamat dan nilai proyek tidak ikut ke situs.</p>
                            </div>
                        )}
                    </div>
                </SectionCard>

                <div className="flex flex-col gap-6 lg:col-span-2 lg:row-start-1">
                    <Form {...form}>
                        <form onSubmit={form.handleSubmit(onSubmit)}>
                            <SectionCard
                                title="Data Portofolio"
                                icon={FileText}
                                footer={
                                    <div className="flex items-center justify-end gap-2">
                                        <Button type="button" variant="ghost" size="sm" disabled={!dirty} onClick={() => form.reset()}>
                                            Batalkan
                                        </Button>
                                        <Button type="submit" disabled={form.formState.isSubmitting}>
                                            Simpan
                                        </Button>
                                    </div>
                                }
                            >
                                <div className="space-y-4">
                                    <PortfolioBaseFields control={form.control} projectTypes={projectTypes} cities={cities} />

                                    <FormField
                                        control={form.control}
                                        name="summary"
                                        render={({ field }) => (
                                            <FormItem>
                                                <div className="flex items-center justify-between gap-2">
                                                    <FormLabel>Ringkasan</FormLabel>
                                                    <CharCounter value={summary} max={300} />
                                                </div>
                                                <FormControl>
                                                    <Textarea {...field} rows={2} placeholder="mis. Kitchen set L 4 meter dengan island, HPL putih doff dan top table granit." />
                                                </FormControl>
                                                <FormDescription>Satu-dua kalimat yang tampil di kartu portofolio.</FormDescription>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                    <FormField
                                        control={form.control}
                                        name="description"
                                        render={({ field }) => (
                                            <FormItem>
                                                <div className="flex items-center justify-between gap-2">
                                                    <FormLabel>Cerita Proyek</FormLabel>
                                                    <CharCounter value={description} max={5000} />
                                                </div>
                                                <FormControl>
                                                    <Textarea {...field} rows={7} />
                                                </FormControl>
                                                <FormDescription>
                                                    Kebutuhan klien, solusi, material dan lama pengerjaan. Baris kosong = paragraf baru.
                                                </FormDescription>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                    <FormField
                                        control={form.control}
                                        name="sort_order"
                                        render={({ field }) => (
                                            <FormItem className="max-w-48">
                                                <FormLabel>Urutan Tampil</FormLabel>
                                                <FormControl>
                                                    <Input {...field} inputMode="numeric" onChange={(event) => field.onChange(event.target.value.replace(/\D/g, ''))} />
                                                </FormControl>
                                                <FormDescription>Angka kecil tampil lebih dulu.</FormDescription>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                    <FormField
                                        control={form.control}
                                        name="client_consent"
                                        render={({ field }) => (
                                            <FormItem className="flex flex-row items-start justify-between gap-4 rounded-lg border border-daiku-border p-3">
                                                <div className="space-y-1">
                                                    <FormLabel className="cursor-pointer">Klien setuju proyeknya ditampilkan</FormLabel>
                                                    <FormDescription>
                                                        Wajib sebelum terbit. Mintalah izin klien untuk foto dan cerita ini (tanpa nama dan alamatnya). Mematikannya
                                                        lalu menyimpan langsung menarik portofolio dari situs.
                                                    </FormDescription>
                                                    <FormMessage />
                                                </div>
                                                <FormControl>
                                                    <Switch checked={field.value} onCheckedChange={field.onChange} />
                                                </FormControl>
                                            </FormItem>
                                        )}
                                    />
                                </div>
                            </SectionCard>
                        </form>
                    </Form>

                    <PortfolioPhotoManager
                        itemId={item.id}
                        photos={photos}
                        coverPhotoId={item.cover_photo_id}
                        maxFiles={maxFiles}
                        published={item.is_published}
                    />
                </div>
            </div>
        </AppLayout>
    );
}
