import { EmptyState } from '@/Components/shared/EmptyState';
import { PageHeader } from '@/Components/shared/PageHeader';
import { PushOptIn } from '@/Components/shared/PushOptIn';
import { SectionCard } from '@/Components/shared/SectionCard';
import { Button } from '@/Components/ui/button';
import { Form, FormControl, FormField, FormItem, FormLabel } from '@/Components/ui/form';
import { Switch } from '@/Components/ui/switch';
import AppLayout from '@/Layouts/AppLayout';
import { formatRelative } from '@/lib/format';
import type { NotificationCategory, NotificationPreferences, PageProps } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router, usePage } from '@inertiajs/react';
import { BellRing, Laptop, Smartphone, Trash2, Volume2 } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

interface CategoryOption {
    value: NotificationCategory;
    label: string;
    /** Carries "klien menunggu" (P1) types — those still arrive, only silently. */
    client_waiting: boolean;
}

interface Device {
    id: number;
    user_agent: string | null;
    last_used_at: string | null;
    created_at: string | null;
}

interface Props {
    categories: CategoryOption[];
    preferences: NotificationPreferences;
    devices: Device[];
}

// Mirrors UpdateNotificationPreferenceRequest.
const schema = z.object({
    muted_categories: z.array(z.string()),
    sound: z.boolean(),
});

type FormValues = z.infer<typeof schema>;

/** "Chrome · Android" from a user agent — enough to tell one's devices apart. */
function deviceLabel(userAgent: string | null): { label: string; phone: boolean } {
    const ua = userAgent ?? '';
    const browser = /Edg\//.test(ua) ? 'Edge' : /OPR\//.test(ua) ? 'Opera' : /Firefox\//.test(ua) ? 'Firefox' : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : 'Browser';
    const os = /Android/.test(ua)
        ? 'Android'
        : /iPhone|iPad|iPod/.test(ua)
          ? 'iPhone/iPad'
          : /Windows/.test(ua)
            ? 'Windows'
            : /Mac OS X/.test(ua)
              ? 'Mac'
              : /Linux/.test(ua)
                ? 'Linux'
                : 'perangkat';

    return { label: `${browser} · ${os}`, phone: /Android|iPhone|iPad|iPod|Mobile/.test(ua) };
}

/**
 * Sprint 18 Sub 05 — Pengaturan Notifikasi. The bell always records
 * everything; this page only decides what may ring a device and whether
 * the open app chimes.
 */
export default function NotificationSettings({ categories, preferences, devices }: Props) {
    const { webPushKey } = usePage<PageProps>().props;
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: preferences,
    });

    function onSubmit(values: FormValues) {
        router.patch(route('profile.notifications.update'), values, {
            preserveScroll: true,
            onError: (errors) => {
                Object.entries(errors).forEach(([field, message]) => {
                    form.setError(field as keyof FormValues, { message: message as string });
                });
            },
        });
    }

    return (
        <AppLayout breadcrumbs={[{ label: 'Pengaturan Notifikasi' }]}>
            <Head title="Pengaturan Notifikasi" />

            <PageHeader
                title="Pengaturan Notifikasi"
                description="Atur notifikasi mana yang membunyikan HP/laptop Anda. Semua notifikasi tetap tercatat di lonceng."
                icon={BellRing}
            />

            <div className="grid max-w-3xl gap-6">
                <SectionCard
                    title="Perangkat ini"
                    description="HP/laptop yang sedang Anda pakai."
                    icon={Smartphone}
                    action={
                        webPushKey &&
                        devices.length > 0 && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => router.post(route('push-subscriptions.test'), {}, { preserveScroll: true })}
                            >
                                Kirim notifikasi uji
                            </Button>
                        )
                    }
                >
                    {webPushKey ? (
                        <PushOptIn />
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Notifikasi ke perangkat belum diaktifkan di server. Lonceng dan pemberitahuan di aplikasi tetap berjalan.
                        </p>
                    )}
                </SectionCard>

                <SectionCard title="Perangkat terdaftar" description="Perangkat yang menerima notifikasi akun ini." icon={Laptop} flush>
                    {devices.length === 0 ? (
                        <EmptyState title="Belum ada perangkat" description="Aktifkan notifikasi di HP atau laptop Anda." />
                    ) : (
                        <ul className="divide-y divide-border">
                            {devices.map((device) => {
                                const { label, phone } = deviceLabel(device.user_agent);
                                const Icon = phone ? Smartphone : Laptop;

                                return (
                                    <li key={device.id} className="flex items-center gap-3 px-5 py-3">
                                        <Icon className="size-4 shrink-0 text-daiku-muted" />
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-medium text-foreground">{label}</p>
                                            <p className="text-xs text-muted-foreground">
                                                {device.last_used_at
                                                    ? `Terakhir menerima ${formatRelative(device.last_used_at)}`
                                                    : `Didaftarkan ${device.created_at ? formatRelative(device.created_at) : ''}`}
                                            </p>
                                        </div>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            aria-label={`Hapus ${label}`}
                                            title="Hapus perangkat"
                                            onClick={() =>
                                                router.delete(route('push-subscriptions.destroy', { pushSubscription: device.id }), {
                                                    preserveScroll: true,
                                                })
                                            }
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </SectionCard>

                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)}>
                        <SectionCard
                            title="Bunyi & push per kategori"
                            description="Matikan kategori yang tidak perlu membunyikan perangkat Anda."
                            icon={Volume2}
                            footer={
                                <Button type="submit" disabled={form.formState.isSubmitting}>
                                    Simpan Pengaturan
                                </Button>
                            }
                        >
                            <div className="space-y-3">
                                <FormField
                                    control={form.control}
                                    name="sound"
                                    render={({ field }) => (
                                        <FormItem className="flex flex-row items-center justify-between gap-3 rounded-lg border border-daiku-border p-3">
                                            <div>
                                                <FormLabel className="cursor-pointer">Bunyi di aplikasi</FormLabel>
                                                <p className="text-xs text-muted-foreground">
                                                    Bunyi singkat saat ada notifikasi penting dan Daiku sedang terbuka.
                                                </p>
                                            </div>
                                            <FormControl>
                                                <Switch checked={field.value} onCheckedChange={field.onChange} />
                                            </FormControl>
                                        </FormItem>
                                    )}
                                />

                                <FormField
                                    control={form.control}
                                    name="muted_categories"
                                    render={({ field }) => (
                                        <>
                                            {categories.map((category) => {
                                                const on = !field.value.includes(category.value);

                                                return (
                                                    <FormItem
                                                        key={category.value}
                                                        className="flex flex-row items-center justify-between gap-3 rounded-lg border border-daiku-border p-3"
                                                    >
                                                        <div>
                                                            <FormLabel className="cursor-pointer">{category.label}</FormLabel>
                                                            {category.client_waiting && !on && (
                                                                <p className="text-xs text-muted-foreground">
                                                                    Urusan yang ditunggu klien tetap masuk ke perangkat, hanya tanpa bunyi.
                                                                </p>
                                                            )}
                                                        </div>
                                                        <FormControl>
                                                            <Switch
                                                                checked={on}
                                                                onCheckedChange={(checked) =>
                                                                    field.onChange(
                                                                        checked
                                                                            ? field.value.filter((value) => value !== category.value)
                                                                            : [...field.value, category.value],
                                                                    )
                                                                }
                                                            />
                                                        </FormControl>
                                                    </FormItem>
                                                );
                                            })}
                                        </>
                                    )}
                                />
                            </div>
                        </SectionCard>
                    </form>
                </Form>
            </div>
        </AppLayout>
    );
}
