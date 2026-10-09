import { CharCounter } from '@/Components/modules/settings/CharCounter';
import { PublishChip } from '@/Components/modules/settings/PortfolioFields';
import { ModuleTabs } from '@/Components/shared/ModuleTabs';
import { Notice } from '@/Components/shared/Notice';
import { PageHeader } from '@/Components/shared/PageHeader';
import { ProgressBar } from '@/Components/shared/ProgressBar';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Form, FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Switch } from '@/Components/ui/switch';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import type { PageProps, ServicePageContent, ServicePageDetail } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router, usePage } from '@inertiajs/react';
import { CircleHelp, ExternalLink, FileText, Globe, ImageIcon, ImageUp, ListChecks, Loader2, Plus, Search, Trash2, X } from 'lucide-react';
import { type ReactNode, useMemo, useRef, useState } from 'react';
import { useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';

interface ServicePageEditProps {
    page: ServicePageDetail;
    /** The seeded text — to show what still has to be rewritten. */
    placeholder: ServicePageContent;
    /** ServicePage::MAX_FAQS. */
    maxFaqs: number;
}

/** UpdateServicePageRequest: `highlights` max 8. */
const MAX_HIGHLIGHTS = 8;

const PUBLISH_BLOCKED = 'Ganti teks pembuka dan isi halaman dengan tulisan sendiri sebelum halaman ini diterbitkan.';

/** ServicePageService::same() — whitespace differences don't count as a rewrite. */
function same(a: string, b: string): boolean {
    const normalize = (text: string) => text.trim().replace(/\s+/gu, ' ');

    return normalize(a) === normalize(b);
}

const isBlank = (text: string) => text.trim() === '';

// Mirrors App\Http\Requests\CompanyProfile\UpdateServicePageRequest. Empty
// highlight/FAQ rows are dropped (as prepareForValidation does) before
// sending; a half-filled FAQ needs both its question and answer.
function makeSchema(placeholder: ServicePageContent) {
    return z
        .object({
            title: z.string().trim().min(1, 'Nama layanan wajib diisi.').max(120, 'Nama layanan maksimal 120 karakter.'),
            headline: z.string().trim().min(1, 'Judul halaman wajib diisi.').max(150, 'Judul halaman maksimal 150 karakter.'),
            intro: z.string().trim().min(1, 'Teks pembuka wajib diisi.').max(1000, 'Teks pembuka maksimal 1000 karakter.'),
            body: z.string().trim().min(1, 'Isi halaman wajib diisi.').max(10000, 'Isi halaman maksimal 10.000 karakter.'),
            highlights: z.array(z.object({ value: z.string().max(120, 'Tiap poin keunggulan maksimal 120 karakter.') })),
            faqs: z.array(
                z
                    .object({
                        q: z.string().max(200, 'Pertanyaan maksimal 200 karakter.'),
                        a: z.string().max(1000, 'Jawaban maksimal 1000 karakter.'),
                    })
                    .superRefine((faq, ctx) => {
                        if (isBlank(faq.q) && isBlank(faq.a)) return;
                        if (isBlank(faq.q)) ctx.addIssue({ code: 'custom', path: ['q'], message: 'Pertanyaan wajib diisi.' });
                        if (isBlank(faq.a)) ctx.addIssue({ code: 'custom', path: ['a'], message: 'Jawaban wajib diisi.' });
                    }),
            ),
            meta_description: z.string().trim().min(1, 'Deskripsi Google wajib diisi.').max(160, 'Deskripsi Google maksimal 160 karakter.'),
            is_published: z.boolean(),
        })
        .superRefine((values, ctx) => {
            // ServicePageService::update() refuses the same.
            if (values.is_published && (same(values.intro, placeholder.intro) || same(values.body, placeholder.body))) {
                ctx.addIssue({ code: 'custom', path: ['is_published'], message: PUBLISH_BLOCKED });
            }
        });
}

type FormValues = z.infer<ReturnType<typeof makeSchema>>;

function toValues(page: ServicePageDetail): FormValues {
    return {
        title: page.title,
        headline: page.headline,
        intro: page.intro,
        body: page.body,
        highlights: page.highlights.map((value) => ({ value })),
        faqs: page.faqs.map((faq) => ({ q: faq.q, a: faq.a })),
        meta_description: page.meta_description,
        is_published: page.is_published,
    };
}

/** "Teks contoh" next to a label whose value still equals the seeded text. */
function PlaceholderMark({ show, blocking }: { show: boolean; blocking?: boolean }) {
    if (!show) return null;

    return <StatusChip status="PLACEHOLDER" label={blocking ? 'Teks contoh — wajib diganti' : 'Teks contoh'} tone="warning" />;
}

function LabelRow({ children }: { children: ReactNode }) {
    return <div className="flex flex-wrap items-center justify-between gap-2">{children}</div>;
}

// Mirrors UploadServicePageHeroRequest.
const HERO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const HERO_MAX_BYTES = 15 * 1024 * 1024;

function readSize(file: File): Promise<{ width: number; height: number } | null> {
    return new Promise((resolve) => {
        const url = URL.createObjectURL(file);
        const image = new Image();
        image.onload = () => {
            resolve({ width: image.naturalWidth, height: image.naturalHeight });
            URL.revokeObjectURL(url);
        };
        image.onerror = () => {
            resolve(null);
            URL.revokeObjectURL(url);
        };
        image.src = url;
    });
}

/** The page's own photo — uploaded/removed at once, independent of "Simpan". */
function HeroCard({ page }: { page: ServicePageDetail }) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [error, setError] = useState<string | null>(null);
    const [progress, setProgress] = useState<number | null>(null);

    async function pick(file: File | undefined) {
        if (inputRef.current) inputRef.current.value = '';
        if (!file) return;

        if (!HERO_TYPES.includes(file.type) && !/\.(jpe?g|png|webp)$/i.test(file.name)) {
            setError('Foto harus berformat JPG, PNG, atau WEBP.');
            return;
        }
        if (file.size > HERO_MAX_BYTES) {
            setError('Ukuran foto maksimal 15 MB.');
            return;
        }
        const size = await readSize(file);
        if (size && (size.width < 800 || size.height < 500 || size.width > 8000 || size.height > 8000)) {
            setError(`Foto minimal 800×500 piksel, maksimal 8000 piksel (ukurannya ${size.width}×${size.height}).`);
            return;
        }

        setError(null);
        router.post(
            route('settings.service-pages.hero.store', { servicePage: page.id }),
            { file },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => setProgress(0),
                onProgress: (event) => setProgress(Math.round(event?.percentage ?? 0)),
                onError: (errors) => setError(errors.file ?? 'Gagal mengunggah foto.'),
                onFinish: () => setProgress(null),
            },
        );
    }

    function remove() {
        if (!confirm('Hapus foto halaman ini? Halaman akan memakai tampilan tanpa foto.')) return;
        router.delete(route('settings.service-pages.hero.destroy', { servicePage: page.id }), { preserveScroll: true });
    }

    const uploading = progress !== null;

    return (
        <SectionCard title="Foto Halaman" icon={ImageIcon} description="Foto di bagian atas halaman layanan ini. Pakai hasil proyek sendiri yang sejenis.">
            <div className="space-y-3">
                <button
                    type="button"
                    onClick={() => inputRef.current?.click()}
                    disabled={uploading}
                    aria-label={page.hero_url ? 'Ganti foto halaman' : 'Unggah foto halaman'}
                    className="relative flex aspect-16/10 w-full items-center justify-center overflow-hidden rounded-xl border border-border bg-daiku-gray/60 hover:bg-daiku-gray focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                >
                    {page.hero_url ? (
                        <img src={page.hero_url} alt="" className="absolute inset-0 size-full object-cover" />
                    ) : (
                        <ImageIcon className="size-10 text-daiku-muted/50" />
                    )}
                    {uploading && (
                        <span className="absolute inset-0 flex flex-col items-center justify-center gap-1 bg-background/80 text-xs font-medium">
                            <Loader2 className="size-5 animate-spin text-muted-foreground" />
                            {progress < 100 ? `Mengunggah… ${progress}%` : 'Memproses foto…'}
                        </span>
                    )}
                </button>
                {uploading && <ProgressBar value={progress} label="Progres unggah foto" />}
                <p className="text-[11px] text-muted-foreground">
                    JPG/PNG/WEBP, maks. 15 MB, minimal 800×500 piksel. Diperkecil ke WebP dan data lokasi (GPS) dihapus otomatis.
                </p>
                {error && <p className="text-xs text-error-ink">{error}</p>}
                <div className="flex items-center gap-2">
                    <Button type="button" variant="outline" size="sm" disabled={uploading} onClick={() => inputRef.current?.click()}>
                        <ImageUp className="size-3.5" />
                        {page.hero_url ? 'Ganti' : 'Unggah'}
                    </Button>
                    {page.hero_url && (
                        <Button type="button" variant="ghost" size="sm" disabled={uploading} onClick={remove} className="text-error-ink hover:text-error-ink">
                            <Trash2 className="size-3.5" />
                            Hapus
                        </Button>
                    )}
                </div>
                <input ref={inputRef} type="file" accept=".jpg,.jpeg,.png,.webp" className="hidden" onChange={(event) => void pick(event.target.files?.[0])} />
            </div>
        </SectionCard>
    );
}

/** Roughly how Google lists the page: URL, title, description. */
function GooglePreview({ url, title, description }: { url: string; title: string; description: string }) {
    let crumb = url;
    try {
        const parsed = new URL(url);
        crumb = [parsed.host, ...parsed.pathname.split('/').filter(Boolean)].join(' › ');
    } catch {
        // Not a full URL — show it as is.
    }

    return (
        <div className="rounded-lg border border-border bg-background p-3">
            <p className="truncate text-xs text-muted-foreground">{crumb}</p>
            <p className="mt-0.5 truncate text-base text-info-ink">{title}</p>
            <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{description || 'Deskripsi belum diisi.'}</p>
        </div>
    );
}

/**
 * Sprint 20 Sub 06 (K4) — the text of one service page (/layanan/{slug}).
 * Pages that read the same as one another rank lower, so every page gets
 * its own text; publishing is refused while the intro or body is still
 * the seeded placeholder (ServicePageService::update()).
 */
export default function ServicePageEdit({ page, placeholder, maxFaqs }: ServicePageEditProps) {
    const { site } = usePage<PageProps>().props;
    const schema = useMemo(() => makeSchema(placeholder), [placeholder]);
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: toValues(page) });
    const highlights = useFieldArray({ control: form.control, name: 'highlights' });
    const faqs = useFieldArray({ control: form.control, name: 'faqs' });

    const values = form.watch();
    const dirty = form.formState.isDirty;

    // Which parts still read like the seeded text (live, from the unsaved values).
    const still = {
        title: same(values.title, placeholder.title),
        headline: same(values.headline, placeholder.headline),
        intro: same(values.intro, placeholder.intro),
        body: same(values.body, placeholder.body),
        highlights:
            placeholder.highlights.length > 0 &&
            values.highlights.map((row) => row.value.trim()).filter(Boolean).join('\n') === placeholder.highlights.join('\n'),
        faqs:
            placeholder.faqs.length > 0 &&
            values.faqs.length === placeholder.faqs.length &&
            values.faqs.every((faq, index) => same(faq.q, placeholder.faqs[index].q) && same(faq.a, placeholder.faqs[index].a)),
        meta_description: same(values.meta_description, placeholder.meta_description),
    };
    const blocked = still.intro || still.body;
    const stillLabels = [
        still.headline && 'judul halaman',
        still.intro && 'teks pembuka',
        still.body && 'isi halaman',
        still.highlights && 'poin keunggulan',
        still.faqs && 'FAQ',
        still.meta_description && 'deskripsi Google',
    ].filter((label): label is string => Boolean(label));

    function onSubmit(values: FormValues) {
        // Drop empty rows first so the server's error keys (faqs.0.q…) line up with the rows shown.
        const cleanHighlights = values.highlights.filter((row) => !isBlank(row.value));
        const cleanFaqs = values.faqs.filter((faq) => !isBlank(faq.q) || !isBlank(faq.a));
        form.setValue('highlights', cleanHighlights);
        form.setValue('faqs', cleanFaqs);

        router.put(
            route('settings.service-pages.update', { servicePage: page.id }),
            {
                title: values.title.trim(),
                headline: values.headline.trim(),
                intro: values.intro.trim(),
                body: values.body.trim(),
                highlights: cleanHighlights.map((row) => row.value.trim()),
                faqs: cleanFaqs.map((faq) => ({ q: faq.q.trim(), a: faq.a.trim() })),
                meta_description: values.meta_description.trim(),
                is_published: values.is_published,
            },
            {
                preserveScroll: true,
                onSuccess: (response) => form.reset(toValues(response.props.page as ServicePageDetail)),
                onError: (errors) =>
                    Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof FormValues, { message })),
            },
        );
    }

    const counted = (name: 'title' | 'headline' | 'intro' | 'body' | 'meta_description', max: number) => <CharCounter value={values[name]} max={max} />;

    return (
        <AppLayout breadcrumbs={[{ label: page.title }]}>
            <Head title={`Halaman ${page.title}`} />

            <PageHeader
                title={page.title}
                icon={Globe}
                description={`Jenis proyek: ${page.types.join(', ')}. Portofolio terbit dengan jenis ini tampil otomatis di halaman.`}
                actions={
                    <>
                        <PublishChip published={page.is_published} />
                        <Button asChild variant="outline" size="sm">
                            <a href={page.public_url} target="_blank" rel="noopener noreferrer">
                                <ExternalLink className="size-3.5" />
                                Lihat halaman
                            </a>
                        </Button>
                    </>
                }
            />

            <ModuleTabs />

            {stillLabels.length > 0 && (
                <Notice tone="warning" className="mb-6">
                    Masih teks contoh: {stillLabels.join(', ')}. Teks contoh sama polanya di semua halaman layanan — Google menilai halaman yang mirip
                    satu sama lain lebih rendah. {blocked ? 'Teks pembuka dan isi halaman wajib diganti sebelum terbit.' : 'Sebaiknya diganti juga.'}
                </Notice>
            )}

            <Form {...form}>
                <form onSubmit={form.handleSubmit(onSubmit)} className="grid gap-6 lg:grid-cols-3">
                    <div className="flex flex-col gap-6 lg:col-span-2">
                        <SectionCard title="Judul & Pembuka" icon={FileText}>
                            <div className="space-y-4">
                                <FormField
                                    control={form.control}
                                    name="title"
                                    render={({ field }) => (
                                        <FormItem>
                                            <LabelRow>
                                                <FormLabel required>Nama Layanan</FormLabel>
                                                {counted('title', 120)}
                                            </LabelRow>
                                            <FormControl>
                                                <Input {...field} />
                                            </FormControl>
                                            <FormDescription>Di menu Layanan, kartu beranda dan tautan antarhalaman.</FormDescription>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="headline"
                                    render={({ field }) => (
                                        <FormItem>
                                            <LabelRow>
                                                <span className="flex items-center gap-2">
                                                    <FormLabel required>Judul Halaman</FormLabel>
                                                    <PlaceholderMark show={still.headline} />
                                                </span>
                                                {counted('headline', 150)}
                                            </LabelRow>
                                            <FormControl>
                                                <Input {...field} />
                                            </FormControl>
                                            <FormDescription>Judul besar (H1). Sebut layanan dan kota — persis seperti yang diketik orang di Google.</FormDescription>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="intro"
                                    render={({ field }) => (
                                        <FormItem>
                                            <LabelRow>
                                                <span className="flex items-center gap-2">
                                                    <FormLabel required>Teks Pembuka</FormLabel>
                                                    <PlaceholderMark show={still.intro} blocking />
                                                </span>
                                                {counted('intro', 1000)}
                                            </LabelRow>
                                            <FormControl>
                                                <Textarea {...field} rows={4} />
                                            </FormControl>
                                            <FormDescription>Paragraf di bawah judul: untuk siapa layanan ini dan apa bedanya dikerjakan Daiku.</FormDescription>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                            </div>
                        </SectionCard>

                        <SectionCard title="Isi Halaman" icon={FileText}>
                            <FormField
                                control={form.control}
                                name="body"
                                render={({ field }) => (
                                    <FormItem>
                                        <LabelRow>
                                            <span className="flex items-center gap-2">
                                                <FormLabel required>Isi</FormLabel>
                                                <PlaceholderMark show={still.body} blocking />
                                            </span>
                                            {counted('body', 10000)}
                                        </LabelRow>
                                        <FormControl>
                                            <Textarea {...field} rows={14} className="font-mono text-sm" />
                                        </FormControl>
                                        <FormDescription>
                                            Baris kosong = paragraf baru. Baris yang diawali <code className="rounded bg-daiku-gray px-1">## </code> menjadi subjudul,
                                            mis. <code className="rounded bg-daiku-gray px-1">## Material dan pengerjaan</code>. Tanpa HTML — tag tampil apa adanya.
                                        </FormDescription>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </SectionCard>

                        <SectionCard
                            title="Poin Keunggulan"
                            icon={ListChecks}
                            description="Daftar singkat di samping isi halaman. Baris kosong diabaikan."
                            action={<PlaceholderMark show={still.highlights} />}
                        >
                            <div className="space-y-2">
                                {highlights.fields.map((row, index) => (
                                    <FormField
                                        key={row.id}
                                        control={form.control}
                                        name={`highlights.${index}.value`}
                                        render={({ field }) => (
                                            <FormItem>
                                                <div className="flex items-center gap-2">
                                                    <FormControl>
                                                        <Input {...field} aria-label={`Poin keunggulan ${index + 1}`} maxLength={120} />
                                                    </FormControl>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        onClick={() => highlights.remove(index)}
                                                        aria-label={`Hapus poin ${index + 1}`}
                                                    >
                                                        <X className="size-4" />
                                                    </Button>
                                                </div>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                ))}
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={highlights.fields.length >= MAX_HIGHLIGHTS}
                                    onClick={() => highlights.append({ value: '' })}
                                >
                                    <Plus className="size-3.5" />
                                    Tambah Poin ({highlights.fields.length}/{MAX_HIGHLIGHTS})
                                </Button>
                            </div>
                        </SectionCard>

                        <SectionCard
                            title="Pertanyaan Umum (FAQ)"
                            icon={CircleHelp}
                            description="Pertanyaan yang sering ditanyakan calon klien lewat WhatsApp. Bisa tampil langsung di hasil Google."
                            action={<PlaceholderMark show={still.faqs} />}
                        >
                            <div className="space-y-3">
                                {faqs.fields.map((row, index) => (
                                    <div key={row.id} className="space-y-3 rounded-lg border border-daiku-border p-3">
                                        <div className="flex items-center justify-between gap-2">
                                            <p className="text-xs font-semibold text-daiku-muted uppercase">Pertanyaan {index + 1}</p>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-sm"
                                                onClick={() => faqs.remove(index)}
                                                aria-label={`Hapus pertanyaan ${index + 1}`}
                                            >
                                                <Trash2 className="size-4 text-error-ink" />
                                            </Button>
                                        </div>
                                        <FormField
                                            control={form.control}
                                            name={`faqs.${index}.q`}
                                            render={({ field }) => (
                                                <FormItem>
                                                    <FormLabel required>Pertanyaan</FormLabel>
                                                    <FormControl>
                                                        <Input {...field} maxLength={200} />
                                                    </FormControl>
                                                    <FormMessage />
                                                </FormItem>
                                            )}
                                        />
                                        <FormField
                                            control={form.control}
                                            name={`faqs.${index}.a`}
                                            render={({ field }) => (
                                                <FormItem>
                                                    <LabelRow>
                                                        <FormLabel required>Jawaban</FormLabel>
                                                        <CharCounter value={values.faqs[index]?.a} max={1000} />
                                                    </LabelRow>
                                                    <FormControl>
                                                        <Textarea {...field} rows={3} />
                                                    </FormControl>
                                                    <FormMessage />
                                                </FormItem>
                                            )}
                                        />
                                    </div>
                                ))}
                                <Button type="button" variant="outline" size="sm" disabled={faqs.fields.length >= maxFaqs} onClick={() => faqs.append({ q: '', a: '' })}>
                                    <Plus className="size-3.5" />
                                    Tambah Pertanyaan ({faqs.fields.length}/{maxFaqs})
                                </Button>
                            </div>
                        </SectionCard>

                        <SectionCard title="Tampilan di Google" icon={Search}>
                            <div className="space-y-4">
                                <FormField
                                    control={form.control}
                                    name="meta_description"
                                    render={({ field }) => (
                                        <FormItem>
                                            <LabelRow>
                                                <span className="flex items-center gap-2">
                                                    <FormLabel required>Deskripsi Google</FormLabel>
                                                    <PlaceholderMark show={still.meta_description} />
                                                </span>
                                                {counted('meta_description', 160)}
                                            </LabelRow>
                                            <FormControl>
                                                <Textarea {...field} rows={2} />
                                            </FormControl>
                                            <FormDescription>Teks di bawah judul pada hasil pencarian. Sebut layanan, kota dan ajakan konsultasi.</FormDescription>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <div>
                                    <p className="mb-2 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">Perkiraan tampilan</p>
                                    <GooglePreview url={page.public_url} title={`${values.headline} | ${site.name}`} description={values.meta_description} />
                                </div>
                            </div>
                        </SectionCard>
                    </div>

                    <div className="flex flex-col gap-6 lg:sticky lg:top-20 lg:h-fit">
                        <SectionCard
                            title="Terbit"
                            icon={Globe}
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
                            <FormField
                                control={form.control}
                                name="is_published"
                                render={({ field }) => (
                                    <FormItem>
                                        <div className="flex items-start justify-between gap-4">
                                            <div className="space-y-1">
                                                <FormLabel className="cursor-pointer">Terbitkan halaman</FormLabel>
                                                <FormDescription>
                                                    Terbit = diindeks Google dan masuk sitemap. Draf tetap bisa dibuka dari beranda, tetapi disembunyikan dari Google.
                                                </FormDescription>
                                            </div>
                                            <FormControl>
                                                <Switch checked={field.value} onCheckedChange={field.onChange} />
                                            </FormControl>
                                        </div>
                                        {blocked && !field.value && (
                                            <p className="text-xs text-warning-ink">Belum bisa terbit: teks pembuka dan isi halaman masih teks contoh.</p>
                                        )}
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </SectionCard>

                        <HeroCard page={page} />
                    </div>
                </form>
            </Form>
        </AppLayout>
    );
}
