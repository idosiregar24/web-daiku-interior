import { BrandAssetCard } from '@/Components/modules/settings/BrandAssetCard';
import { intOrNull, optionalInt } from '@/Components/modules/settings/companyProfileSchema';
import { Notice } from '@/Components/shared/Notice';
import { SectionCard } from '@/Components/shared/SectionCard';
import { Button } from '@/Components/ui/button';
import { Form, FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { isValidPhone, normalizePhone, sanitizePhoneInput, whatsappNumber } from '@/lib/phone';
import type { PublicProfileDefaults, SiteSetting } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { BarChart3, ExternalLink, Eye, ImageIcon, MessageCircle, SearchCheck, Sparkles } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

/** UpdatePublicProfileRequest::MAPS_EMBED_PREFIX — only Google's own embed may be framed. */
const MAPS_EMBED_PREFIX = 'https://www.google.com/maps/embed?';

const CURRENT_YEAR = new Date().getFullYear();

/** Same as the server: a pasted "Sematkan peta" <iframe> keeps only its src. */
function mapsSrc(value: string): string {
    const trimmed = value.trim();
    const match = trimmed.match(/<iframe[^>]+src="([^"]+)"/i);

    return match ? match[1].replace(/&amp;/g, '&') : trimmed;
}

/** Same as the server: a pasted Search Console <meta> tag keeps only its content. */
function verificationCode(value: string): string {
    const trimmed = value.trim();
    const match = trimmed.match(/content="([^"]+)"/i);

    return match ? match[1] : trimmed;
}

// Mirrors App\Http\Requests\Settings\UpdatePublicProfileRequest (rules + messages).
const schema = z.object({
    public_tagline: z.string().max(120, 'Tagline maksimal 120 karakter.'),
    hero_headline: z.string().max(120, 'Judul utama maksimal 120 karakter.'),
    hero_subheadline: z.string().max(300, 'Subjudul maksimal 300 karakter.'),
    about_text: z.string().max(3000, 'Teks Tentang Kami maksimal 3000 karakter.'),
    founded_year: optionalInt(1950, CURRENT_YEAR, {
        integer: 'Tahun berdiri harus berupa angka.',
        min: 'Tahun berdiri minimal 1950.',
        max: 'Tahun berdiri tidak boleh melewati tahun ini.',
    }),
    stat_projects: optionalInt(1, 100000, {
        integer: 'Jumlah proyek harus berupa angka.',
        min: 'Jumlah proyek minimal 1.',
        max: 'Jumlah proyek maksimal 100.000.',
    }),
    stat_cities: optionalInt(1, 1000, {
        integer: 'Jumlah kota harus berupa angka.',
        min: 'Jumlah kota minimal 1.',
        max: 'Jumlah kota maksimal 1000.',
    }),
    service_area_text: z.string().max(300, 'Area layanan maksimal 300 karakter.'),
    whatsapp_phone: z
        .string()
        .refine((value) => value.trim() === '' || isValidPhone(normalizePhone(value)), 'Nomor WhatsApp harus diawali 08 dan berisi 10–13 angka.'),
    whatsapp_greeting: z.string().max(200, 'Pesan pembuka maksimal 200 karakter.'),
    maps_embed_url: z
        .string()
        .refine((value) => mapsSrc(value).length <= 1000, 'Link peta maksimal 1000 karakter.')
        .refine(
            (value) => value.trim() === '' || mapsSrc(value).startsWith(MAPS_EMBED_PREFIX),
            'Link peta harus dari Google Maps → Bagikan → Sematkan peta (diawali https://www.google.com/maps/embed?).',
        ),
    opening_hours: z.string().max(200, 'Jam buka maksimal 200 karakter.'),
    google_site_verification: z
        .string()
        .refine((value) => verificationCode(value).length <= 100, 'Kode verifikasi maksimal 100 karakter.')
        .refine(
            (value) => value.trim() === '' || /^[A-Za-z0-9_-]+$/.test(verificationCode(value)),
            'Kode verifikasi hanya berisi huruf, angka, - dan _.',
        ),
});

type FormValues = z.infer<typeof schema>;
type TextField = Exclude<keyof FormValues, 'founded_year' | 'stat_projects' | 'stat_cities'>;

function toValues(settings: SiteSetting): FormValues {
    return {
        public_tagline: settings.public_tagline ?? '',
        hero_headline: settings.hero_headline ?? '',
        hero_subheadline: settings.hero_subheadline ?? '',
        about_text: settings.about_text ?? '',
        founded_year: settings.founded_year?.toString() ?? '',
        stat_projects: settings.stat_projects?.toString() ?? '',
        stat_cities: settings.stat_cities?.toString() ?? '',
        service_area_text: settings.service_area_text ?? '',
        whatsapp_phone: settings.whatsapp_phone ?? '',
        whatsapp_greeting: settings.whatsapp_greeting ?? '',
        maps_embed_url: settings.maps_embed_url ?? '',
        opening_hours: settings.opening_hours ?? '',
        google_site_verification: settings.google_site_verification ?? '',
    };
}

/** What the server stores: '' → null, numbers as numbers, pasted tags reduced to their value. */
function toPayload(values: FormValues) {
    const text = (value: string) => (value.trim() === '' ? null : value);

    return {
        public_tagline: text(values.public_tagline),
        hero_headline: text(values.hero_headline),
        hero_subheadline: text(values.hero_subheadline),
        about_text: text(values.about_text),
        founded_year: intOrNull(values.founded_year),
        stat_projects: intOrNull(values.stat_projects),
        stat_cities: intOrNull(values.stat_cities),
        service_area_text: text(values.service_area_text),
        whatsapp_phone: text(normalizePhone(values.whatsapp_phone)),
        whatsapp_greeting: text(values.whatsapp_greeting),
        maps_embed_url: text(mapsSrc(values.maps_embed_url)),
        opening_hours: text(values.opening_hours),
        google_site_verification: text(verificationCode(values.google_site_verification)),
    };
}

/** ProfileContent::whatsappUrl() — the greeting, minus a trailing dot, then the context. */
function whatsappMessage(greeting: string, context: string): string {
    return `${greeting.replace(/[ .]+$/, '')} ${context}.`;
}

/** The hero and the WhatsApp button as visitors meet them, from the unsaved values. */
function PublicPreview({
    settings,
    defaults,
    values,
    siteUrl,
}: {
    settings: SiteSetting;
    defaults: PublicProfileDefaults;
    values: FormValues;
    siteUrl: string;
}) {
    const headline = values.hero_headline.trim() || defaults.hero_headline;
    const subheadline = values.hero_subheadline.trim() || defaults.hero_subheadline;
    const message = whatsappMessage(values.whatsapp_greeting.trim() || defaults.whatsapp_greeting, 'Kitchen Set');
    // Same fallback as the site: the WhatsApp number, else the company phone.
    const number = whatsappNumber(normalizePhone(values.whatsapp_phone)) ?? whatsappNumber(normalizePhone(settings.company_phone));

    return (
        <div className="space-y-4">
            <div>
                <p className="mb-2 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">Bagian atas beranda</p>
                {/* Same shape as the site's hero: wide photo, centred light text, yellow pill. */}
                <div className="relative isolate flex aspect-[2/1] flex-col items-center justify-center overflow-hidden rounded-2xl bg-daiku-dark px-4 text-center">
                    {settings.hero_image_url ? (
                        <img src={settings.hero_image_url} alt="" className="absolute inset-0 -z-10 size-full object-cover" />
                    ) : (
                        <ImageIcon className="absolute top-3 right-3 size-5 text-daiku-cream/40" />
                    )}
                    <div aria-hidden className="absolute inset-0 -z-10 bg-linear-to-b from-daiku-dark/60 via-daiku-dark/25 to-daiku-dark/65" />
                    <p className="text-sm leading-snug font-light text-daiku-cream">{headline}</p>
                    <p className="mt-1 line-clamp-2 text-[10px] leading-snug text-daiku-cream/75">{subheadline}</p>
                    <span className="mt-2 inline-flex rounded-full bg-daiku-yellow px-2.5 py-1 text-[10px] font-medium text-daiku-dark">Konsultasi Gratis</span>
                </div>
            </div>

            <div>
                <p className="mb-2 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">Pesan WhatsApp dari kartu layanan</p>
                <div className="rounded-xl bg-daiku-gray p-3">
                    <p className="w-fit max-w-full rounded-lg rounded-tr-none bg-success/15 px-3 py-2 text-xs text-foreground">{message}</p>
                </div>
                {number ? (
                    <Button asChild variant="outline" size="sm" className="mt-2">
                        <a href={`https://wa.me/${number}?text=${encodeURIComponent(message)}`} target="_blank" rel="noopener noreferrer">
                            <MessageCircle className="size-3.5" />
                            Uji ke {values.whatsapp_phone.trim() ? 'nomor WhatsApp' : 'telepon perusahaan'}
                        </a>
                    </Button>
                ) : (
                    <p className="mt-2 text-xs text-error-ink">Belum ada nomor yang valid — tombol WhatsApp di situs membuka WhatsApp tanpa tujuan.</p>
                )}
            </div>

            <Button asChild variant="outline" size="sm" className="w-full">
                <a href={siteUrl} target="_blank" rel="noopener noreferrer">
                    <ExternalLink className="size-3.5" />
                    Buka situs publik
                </a>
            </Button>
        </div>
    );
}

/**
 * Sprint 20 Sub 05 — Pengaturan Situs → "Profil Publik": the company
 * profile's text at `/` (settings.public-profile.update) and its hero
 * photo (settings.assets hero_image, uploaded on its own).
 */
export function PublicProfileForm({ settings, defaults, siteUrl }: { settings: SiteSetting; defaults: PublicProfileDefaults; siteUrl: string }) {
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: toValues(settings) });
    const values = form.watch();

    function onSubmit(values: FormValues) {
        router.put(route('settings.public-profile.update'), toPayload(values), {
            preserveScroll: true,
            // The saved (normalized) values become the new baseline for isDirty/"Batalkan".
            onSuccess: (page) => form.reset(toValues(page.props.settings as SiteSetting)),
            onError: (errors) => {
                Object.entries(errors).forEach(([field, message]) => {
                    form.setError(field as keyof FormValues, { message: message as string });
                });
            },
        });
    }

    const textField = (name: TextField, label: string, description: string, options: { placeholder?: string; rows?: number; className?: string } = {}) => (
        <FormField
            control={form.control}
            name={name}
            render={({ field }) => (
                <FormItem className={options.className}>
                    <FormLabel>{label}</FormLabel>
                    <FormControl>
                        {options.rows ? <Textarea {...field} rows={options.rows} placeholder={options.placeholder} /> : <Input {...field} placeholder={options.placeholder} />}
                    </FormControl>
                    <FormDescription>{description}</FormDescription>
                    <FormMessage />
                </FormItem>
            )}
        />
    );

    const numberField = (name: 'founded_year' | 'stat_projects' | 'stat_cities', label: string, description: string) => (
        <FormField
            control={form.control}
            name={name}
            render={({ field }) => (
                <FormItem>
                    <FormLabel>{label}</FormLabel>
                    <FormControl>
                        <Input {...field} inputMode="numeric" onChange={(event) => field.onChange(event.target.value.replace(/\D/g, ''))} />
                    </FormControl>
                    <FormDescription>{description}</FormDescription>
                    <FormMessage />
                </FormItem>
            )}
        />
    );

    return (
        <div className="grid gap-6 xl:grid-cols-3">
            <div className="flex flex-col gap-6 xl:col-span-2">
                <Notice>
                    Kolom kosong memakai teks contoh (tampil samar di kolom) selama situs masih dalam masa persiapan; setelah itu bagian yang
                    kosong tidak ditampilkan. Judul utama, subjudul dan pesan WhatsApp selalu memakai teks contoh bila kosong.
                </Notice>

                <SectionCard
                    title="Foto Utama"
                    icon={ImageIcon}
                    description="Foto besar di bagian atas beranda dan gambar saat link situs dibagikan. Langsung tersimpan setelah diunggah."
                >
                    <div className="max-w-sm">
                        <BrandAssetCard
                            asset="hero_image"
                            title="Foto Utama Beranda"
                            description="Pakai foto hasil proyek sendiri, bukan foto stok. Jika kosong, ilustrasi netral dipakai."
                            url={settings.hero_image_url}
                            preview={(url) => <img src={url} alt="Foto utama beranda" className="absolute inset-0 size-full object-cover" />}
                            placeholder={<ImageIcon className="size-10 text-daiku-muted/50" />}
                        />
                    </div>
                </SectionCard>

                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-6">
                        <SectionCard title="Beranda" icon={Sparkles} description="Kalimat pertama yang dibaca calon klien dari Google.">
                            <div className="grid gap-4">
                                {textField('hero_headline', 'Judul Utama', 'Satu kalimat, sebut kota layanan — kata yang dicari orang di Google.', {
                                    placeholder: defaults.hero_headline,
                                })}
                                {textField('hero_subheadline', 'Subjudul', 'Di bawah judul utama: apa yang dikerjakan dan bedanya dengan yang lain.', {
                                    placeholder: defaults.hero_subheadline,
                                    rows: 3,
                                })}
                                {textField('public_tagline', 'Tagline Situs', 'Di samping logo dan di judul tab. Terpisah dari tagline sistem di tab Sistem.', {
                                    placeholder: defaults.public_tagline,
                                })}
                            </div>
                        </SectionCard>

                        <SectionCard
                            title="Angka & Tentang Kami"
                            icon={BarChart3}
                            description="Diisi manual — tidak dihitung dari data proyek. Isi hanya angka yang benar."
                        >
                            <div className="grid gap-4 sm:grid-cols-3">
                                {numberField('founded_year', 'Tahun Berdiri', `1950–${CURRENT_YEAR}.`)}
                                {numberField('stat_projects', 'Proyek Selesai', 'Tampil dengan tanda +, mis. 150+.')}
                                {numberField('stat_cities', 'Kota Terlayani', 'Jumlah kota.')}
                                {textField('about_text', 'Tentang Kami', 'Baris kosong = paragraf baru.', {
                                    placeholder: defaults.about_text,
                                    rows: 6,
                                    className: 'sm:col-span-3',
                                })}
                                {textField('service_area_text', 'Area Layanan', 'Kota dan daerah yang dilayani, di bagian Kontak.', {
                                    placeholder: defaults.service_area_text,
                                    rows: 2,
                                    className: 'sm:col-span-3',
                                })}
                            </div>
                        </SectionCard>

                        <SectionCard
                            title="Kontak & WhatsApp"
                            icon={MessageCircle}
                            description="Semua tombol kontak di situs membuka WhatsApp dengan pesan awal terisi — tidak ada form isian."
                        >
                            <div className="grid gap-4 sm:grid-cols-2">
                                <FormField
                                    control={form.control}
                                    name="whatsapp_phone"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Nomor WhatsApp</FormLabel>
                                            <FormControl>
                                                <Input
                                                    {...field}
                                                    inputMode="tel"
                                                    placeholder={settings.company_phone ? normalizePhone(settings.company_phone) : '081234567890'}
                                                    onChange={(event) => field.onChange(sanitizePhoneInput(event.target.value))}
                                                    onBlur={() => {
                                                        field.onChange(normalizePhone(field.value));
                                                        field.onBlur();
                                                    }}
                                                />
                                            </FormControl>
                                            <FormDescription>Kosong = telepon perusahaan di tab Sistem.</FormDescription>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                {textField('opening_hours', 'Jam Buka', 'Di bagian Kontak dan data Google.', { placeholder: defaults.opening_hours })}
                                {textField(
                                    'whatsapp_greeting',
                                    'Pesan Pembuka WhatsApp',
                                    'Awal pesan yang terisi otomatis; tiap tombol menambahkan konteksnya (layanan atau judul portofolio). Biarkan menyebut "website" agar Marketing mencatat sumber lead Website.',
                                    { placeholder: defaults.whatsapp_greeting, className: 'sm:col-span-2' },
                                )}
                                {textField(
                                    'maps_embed_url',
                                    'Peta Lokasi',
                                    'Google Maps → Bagikan → Sematkan peta → Salin HTML. Boleh tempel seluruh kode <iframe>; link-nya diambil otomatis. Kosong = peta tidak ditampilkan.',
                                    { placeholder: `${MAPS_EMBED_PREFIX}pb=…`, rows: 2, className: 'sm:col-span-2' },
                                )}
                            </div>
                        </SectionCard>

                        <SectionCard
                            title="Google Search Console"
                            icon={SearchCheck}
                            description="Untuk membuktikan ke Google bahwa situs ini milik Daiku, lalu mengirim sitemap."
                            footer={
                                <div className="flex items-center justify-end gap-2">
                                    <Button type="button" variant="ghost" size="sm" disabled={!form.formState.isDirty} onClick={() => form.reset()}>
                                        Batalkan
                                    </Button>
                                    <Button type="submit" disabled={form.formState.isSubmitting}>
                                        Simpan Profil Publik
                                    </Button>
                                </div>
                            }
                        >
                            {textField(
                                'google_site_verification',
                                'Kode Verifikasi',
                                'Search Console → Tambahkan properti → Awalan URL → Tag HTML. Boleh tempel seluruh tag <meta>; kodenya diambil otomatis.',
                                { placeholder: '<meta name="google-site-verification" content="…" />' },
                            )}
                        </SectionCard>
                    </form>
                </Form>
            </div>

            <SectionCard
                title="Pratinjau"
                icon={Eye}
                description="Perubahan teks tampil di sini sebelum disimpan."
                className="h-fit xl:sticky xl:top-20"
            >
                <PublicPreview settings={settings} defaults={defaults} values={values} siteUrl={siteUrl} />
            </SectionCard>
        </div>
    );
}
