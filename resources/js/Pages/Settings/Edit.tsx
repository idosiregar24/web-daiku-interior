import ApplicationLogo from '@/Components/ApplicationLogo';
import { BrandAssetCard } from '@/Components/modules/settings/BrandAssetCard';
import { BrandLogoTile } from '@/Components/shared/BrandMark';
import { ModuleTabs } from '@/Components/shared/ModuleTabs';
import { PageHeader } from '@/Components/shared/PageHeader';
import { SectionCard } from '@/Components/shared/SectionCard';
import { Button } from '@/Components/ui/button';
import {
    Form,
    FormControl,
    FormDescription,
    FormField,
    FormItem,
    FormLabel,
    FormMessage,
} from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import type { SiteSetting } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { Building2, Eye, ImageIcon, Images, LogIn, NotebookText, PenLine, Settings } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

/** Mirrors SiteSetting::DEFAULT_NOTES — shown as placeholders. */
const DEFAULT_NOTES = {
    SURVEY: 'Biaya survey meliputi transportasi dan pengukuran lokasi oleh tim Daiku. Survey dijadwalkan setelah pembayaran diterima.',
    DESAIN: 'Paket desain meliputi: Desain Interior, Desain layout, 3D Render, Gambar spesifikasi, 2 kali Revisi Desain, dan Handover kit.',
    PROYEK: 'Harga sudah termasuk material, upah kerja, dan pemasangan sesuai rincian di atas. Pekerjaan di luar rincian dihitung sebagai pekerjaan tambah.',
};

// Mirrors UpdateSiteSettingRequest.
const schema = z.object({
    site_name: z.string().min(1, 'Nama sistem wajib diisi').max(100, 'Nama sistem maksimal 100 karakter'),
    site_tagline: z.string().max(60, 'Tagline maksimal 60 karakter').optional(),
    login_headline: z.string().max(120, 'Judul halaman login maksimal 120 karakter').optional(),
    company_address: z.string().optional(),
    company_phone: z.string().max(50, 'Telepon maksimal 50 karakter').optional(),
    company_email: z.string().email('Format email tidak valid').optional().or(z.literal('')),
    // Sprint 15 — letterhead, signer, default RAB notes.
    company_instagram: z.string().max(100, 'Instagram maksimal 100 karakter').optional(),
    company_legal_name: z.string().max(150, 'Nama badan usaha maksimal 150 karakter').optional(),
    letter_footer: z.string().max(200, 'Teks footer maksimal 200 karakter').optional(),
    signer_name: z.string().max(100, 'Nama penanda tangan maksimal 100 karakter').optional(),
    signer_title: z.string().max(100, 'Jabatan maksimal 100 karakter').optional(),
    note_survey: z.string().max(2000, 'Catatan maksimal 2000 karakter').optional(),
    note_desain: z.string().max(2000, 'Catatan maksimal 2000 karakter').optional(),
    note_proyek: z.string().max(2000, 'Catatan maksimal 2000 karakter').optional(),
});

type FormValues = z.infer<typeof schema>;

// Fallbacks shown when optional text is empty — same as SiteSetting::DEFAULT_*.
const DEFAULT_TAGLINE = 'Enterprise System';
const DEFAULT_LOGIN_HEADLINE = 'Satu sistem untuk seluruh alur proyek interior.';

/** Sidebar brand + login panel, rendered from the unsaved form values. */
function LivePreview({ settings, values }: { settings: SiteSetting; values: FormValues }) {
    const name = values.site_name || settings.site_name;
    const tagline = values.site_tagline || DEFAULT_TAGLINE;
    const headline = values.login_headline || DEFAULT_LOGIN_HEADLINE;
    const photo = settings.login_image_url;

    return (
        <div className="space-y-4">
            <div>
                <p className="mb-2 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">Sidebar</p>
                <div className="flex items-center gap-3 rounded-xl bg-daiku-gray p-3">
                    {settings.logo_url ? (
                        <BrandLogoTile src={settings.logo_url} className="h-9 max-w-32" />
                    ) : (
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-daiku-yellow">
                            <ApplicationLogo className="size-5 fill-daiku-dark" />
                        </span>
                    )}
                    <span className="min-w-0 leading-tight">
                        <span className="block truncate text-sm font-semibold text-daiku-dark">{name}</span>
                        <span className="block truncate text-[11px] text-daiku-muted">{tagline}</span>
                    </span>
                </div>
            </div>

            <div>
                <p className="mb-2 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">Tab browser</p>
                <div className="flex w-fit max-w-full items-center gap-2 rounded-t-lg border border-b-0 border-border bg-background px-3 py-2 text-xs">
                    {settings.favicon_url || settings.logo_url ? (
                        <img src={settings.favicon_url ?? settings.logo_url ?? ''} alt="" className="size-4 object-contain" />
                    ) : (
                        <ApplicationLogo className="size-4 fill-daiku-muted" />
                    )}
                    <span className="truncate text-foreground">Dashboard - {name}</span>
                </div>
            </div>

            <div>
                <p className="mb-2 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">Halaman login</p>
                <div className="relative flex h-48 flex-col justify-end overflow-hidden rounded-xl bg-daiku-yellow p-4">
                    {photo ? (
                        <>
                            <img src={photo} alt="" className="absolute inset-0 size-full object-cover" />
                            <div aria-hidden className="absolute inset-0 bg-linear-to-t from-daiku-dark/85 via-daiku-dark/30 to-daiku-dark/10" />
                        </>
                    ) : (
                        <>
                            <div aria-hidden className="absolute -top-16 -left-12 size-48 rounded-full bg-daiku-cream opacity-90 blur-2xl" />
                            <div aria-hidden className="absolute -right-8 -bottom-12 size-48 rounded-full bg-daiku-yellow-dark opacity-90 blur-2xl" />
                        </>
                    )}
                    <div className="relative">
                        <p className={photo ? 'text-[10px] text-daiku-cream/80' : 'text-[10px] text-daiku-dark/70'}>
                            {name} {tagline}
                        </p>
                        <p className={photo ? 'mt-1 text-base leading-snug font-semibold text-daiku-cream' : 'mt-1 text-base leading-snug font-semibold text-daiku-dark'}>
                            {headline}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function SettingsEdit({ settings }: { settings: SiteSetting }) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: {
            site_name: settings.site_name,
            site_tagline: settings.site_tagline ?? '',
            login_headline: settings.login_headline ?? '',
            company_address: settings.company_address ?? '',
            company_phone: settings.company_phone ?? '',
            company_email: settings.company_email ?? '',
            company_instagram: settings.company_instagram ?? '',
            company_legal_name: settings.company_legal_name ?? '',
            letter_footer: settings.letter_footer ?? '',
            signer_name: settings.signer_name ?? '',
            signer_title: settings.signer_title ?? '',
            note_survey: settings.note_survey ?? '',
            note_desain: settings.note_desain ?? '',
            note_proyek: settings.note_proyek ?? '',
        },
    });

    const values = form.watch();

    function onSubmit(values: FormValues) {
        router.put(route('settings.update'), values, {
            preserveScroll: true,
            // The saved values become the new baseline for isDirty/"Batalkan".
            onSuccess: () => form.reset(values),
            onError: (errors) => {
                Object.entries(errors).forEach(([field, message]) => {
                    form.setError(field as keyof FormValues, { message: message as string });
                });
            },
        });
    }

    return (
        <AppLayout>
            <Head title="Pengaturan Situs" />

            <PageHeader
                title="Pengaturan Situs"
                icon={Settings}
                description="Kustomisasi identitas, logo, dan halaman login sistem — khusus CEO dan SuperAdmin."
            />

            <ModuleTabs />

            <div className="grid gap-6 xl:grid-cols-3">
                <div className="flex flex-col gap-6 xl:col-span-2">
                    <SectionCard
                        title="Logo & Gambar"
                        icon={Images}
                        description="Klik atau seret file ke kotak pratinjau, atur crop-nya, lalu gambar langsung diunggah — tanpa perlu menekan Simpan."
                    >
                        <div className="grid gap-4 md:grid-cols-3">
                            <BrandAssetCard
                                asset="logo"
                                title="Logo"
                                description="Sidebar, halaman login, dan PDF penawaran/invoice."
                                url={settings.logo_url}
                                preview={(url) => <BrandLogoTile src={url} className="h-24 max-w-[85%] rounded-2xl" />}
                                placeholder={<ApplicationLogo className="size-12 fill-daiku-muted/50" />}
                            />
                            <BrandAssetCard
                                asset="favicon"
                                title="Favicon"
                                description="Ikon kecil di tab browser. Jika kosong, logo dipakai."
                                url={settings.favicon_url}
                                preview={(url) => <BrandLogoTile src={url} className="h-16 rounded-xl" />}
                                placeholder={<ImageIcon className="size-10 text-daiku-muted/50" />}
                            />
                            <BrandAssetCard
                                asset="login_image"
                                title="Gambar Halaman Login"
                                description="Panel kiri halaman login. Jika kosong, gradien emas bawaan dipakai."
                                url={settings.login_image_url}
                                preview={(url) => <img src={url} alt="Gambar login" className="absolute inset-0 size-full object-cover" />}
                                placeholder={<LogIn className="size-10 text-daiku-muted/50" />}
                            />
                        </div>
                    </SectionCard>

                    <Form {...form}>
                        <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-6">
                            <SectionCard title="Identitas & Halaman Login" icon={LogIn}>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormField
                                        control={form.control}
                                        name="site_name"
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormLabel required>Nama Sistem</FormLabel>
                                                <FormControl>
                                                    <Input {...field} />
                                                </FormControl>
                                                <FormDescription>Sidebar, judul tab browser, dan PDF.</FormDescription>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                    <FormField
                                        control={form.control}
                                        name="site_tagline"
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormLabel>Tagline</FormLabel>
                                                <FormControl>
                                                    <Input {...field} placeholder={DEFAULT_TAGLINE} />
                                                </FormControl>
                                                <FormDescription>Teks kecil di bawah nama sistem.</FormDescription>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                    <FormField
                                        control={form.control}
                                        name="login_headline"
                                        render={({ field }) => (
                                            <FormItem className="sm:col-span-2">
                                                <FormLabel>Judul Halaman Login</FormLabel>
                                                <FormControl>
                                                    <Input {...field} placeholder={DEFAULT_LOGIN_HEADLINE} />
                                                </FormControl>
                                                <FormDescription>Kalimat besar di panel kiri halaman login.</FormDescription>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                </div>
                            </SectionCard>

                            <SectionCard
                                title="Profil Perusahaan"
                                icon={Building2}
                                description="Dicetak di kop surat PDF penawaran & invoice dan di halaman link klien."
                            >
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormField
                                        control={form.control}
                                        name="company_address"
                                        render={({ field }) => (
                                            <FormItem className="sm:col-span-2">
                                                <FormLabel>Alamat Perusahaan</FormLabel>
                                                <FormControl>
                                                    <Textarea {...field} />
                                                </FormControl>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                    <FormField
                                        control={form.control}
                                        name="company_phone"
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormLabel>Telepon</FormLabel>
                                                <FormControl>
                                                    <Input {...field} />
                                                </FormControl>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                    <FormField
                                        control={form.control}
                                        name="company_email"
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormLabel>Email</FormLabel>
                                                <FormControl>
                                                    <Input type="email" {...field} />
                                                </FormControl>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                    <FormField
                                        control={form.control}
                                        name="company_instagram"
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormLabel>Instagram</FormLabel>
                                                <FormControl>
                                                    <Input {...field} />
                                                </FormControl>
                                                <FormDescription>Tanpa @, mis. DaikuInterior.</FormDescription>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                    <FormField
                                        control={form.control}
                                        name="company_legal_name"
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormLabel>Nama Badan Usaha</FormLabel>
                                                <FormControl>
                                                    <Input {...field} />
                                                </FormControl>
                                                <FormDescription>Pemilik rekening di catatan pembayaran (a.n.), mis. PT Daiku Shankara Kreasitech.</FormDescription>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                </div>
                            </SectionCard>

                            {/* Sprint 15 K3 — who signs the letters. */}
                            <SectionCard
                                title="Surat & Tanda Tangan"
                                icon={PenLine}
                                description="Penutup surat penawaran & invoice: Hormat kami, tanda tangan, nama, dan footer hitam."
                            >
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormField
                                        control={form.control}
                                        name="signer_name"
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormLabel>Nama Penanda Tangan</FormLabel>
                                                <FormControl>
                                                    <Input {...field} />
                                                </FormControl>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                    <FormField
                                        control={form.control}
                                        name="signer_title"
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormLabel>Jabatan</FormLabel>
                                                <FormControl>
                                                    <Input {...field} />
                                                </FormControl>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                    <FormField
                                        control={form.control}
                                        name="letter_footer"
                                        render={({ field }) => (
                                            <FormItem className="sm:col-span-2">
                                                <FormLabel>Teks Footer Surat</FormLabel>
                                                <FormControl>
                                                    <Input {...field} />
                                                </FormControl>
                                                <FormDescription>Setelah nama perusahaan; kosong = INTERIOR FURNISHING | ARCHITECTURAL DESIGN | BUILDING CONSTRUCTION.</FormDescription>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                    <div className="sm:col-span-2">
                                        <BrandAssetCard
                                            asset="signature"
                                            title="Tanda Tangan"
                                            description="PNG berlatar transparan — langsung tersimpan setelah diunggah. Hanya dicetak di surat, tidak punya alamat publik."
                                            url={settings.signature_url}
                                            preview={(url) => <img src={url} alt="Tanda tangan" className="max-h-24 max-w-[85%] object-contain" />}
                                            placeholder={<PenLine className="size-10 text-daiku-muted/50" />}
                                        />
                                    </div>
                                </div>
                            </SectionCard>

                            {/* Sprint 15 K4 — default "Catatan" per RAB type (an Estimator can change it per RAB). */}
                            <SectionCard
                                title="Catatan Bawaan RAB"
                                icon={NotebookText}
                                description="Dicetak di bagian Catatan surat bila Estimator tidak menulis catatan sendiri. Kosong = teks contoh."
                                footer={
                                    <div className="flex items-center justify-end gap-2">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            disabled={!form.formState.isDirty}
                                            onClick={() => form.reset()}
                                        >
                                            Batalkan
                                        </Button>
                                        <Button type="submit" disabled={form.formState.isSubmitting}>
                                            Simpan Pengaturan
                                        </Button>
                                    </div>
                                }
                            >
                                <div className="grid gap-4">
                                    <FormField
                                        control={form.control}
                                        name="note_survey"
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormLabel>RAB Jasa Survey</FormLabel>
                                                <FormControl>
                                                    <Textarea {...field} rows={3} placeholder={DEFAULT_NOTES.SURVEY} />
                                                </FormControl>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                    <FormField
                                        control={form.control}
                                        name="note_desain"
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormLabel>RAB Jasa Desain</FormLabel>
                                                <FormControl>
                                                    <Textarea {...field} rows={3} placeholder={DEFAULT_NOTES.DESAIN} />
                                                </FormControl>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                    <FormField
                                        control={form.control}
                                        name="note_proyek"
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormLabel>RAB Proyek & RAB lain</FormLabel>
                                                <FormControl>
                                                    <Textarea {...field} rows={3} placeholder={DEFAULT_NOTES.PROYEK} />
                                                </FormControl>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                </div>
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
                    <LivePreview settings={settings} values={values} />
                </SectionCard>
            </div>
        </AppLayout>
    );
}
