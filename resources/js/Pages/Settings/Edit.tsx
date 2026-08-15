import { PageHeader } from '@/Components/shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import {
    Form,
    FormControl,
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
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const schema = z.object({
    site_name: z.string().min(1, 'Nama sistem wajib diisi'),
    company_address: z.string().optional(),
    company_phone: z.string().optional(),
    company_email: z.string().email('Format email tidak valid').optional().or(z.literal('')),
    company_logo_url: z.string().url('URL tidak valid').optional().or(z.literal('')),
});

type FormValues = z.infer<typeof schema>;

export default function SettingsEdit({ settings }: { settings: SiteSetting }) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: {
            site_name: settings.site_name,
            company_address: settings.company_address ?? '',
            company_phone: settings.company_phone ?? '',
            company_email: settings.company_email ?? '',
            company_logo_url: settings.company_logo_url ?? '',
        },
    });

    function onSubmit(values: FormValues) {
        router.put(route('settings.update'), values, {
            onError: (errors) => {
                Object.entries(errors).forEach(([field, message]) => {
                    form.setError(field as keyof FormValues, { message: message as string });
                });
            },
        });
    }

    return (
        <AppLayout breadcrumbs={[{ label: 'Sistem' }, { label: 'Pengaturan Situs' }]}>
            <Head title="Pengaturan Situs" />

            <PageHeader
                title="Pengaturan Situs"
                description="Profil perusahaan & aplikasi — khusus CEO dan SuperAdmin."
            />

            <Card className="max-w-lg">
                <CardContent className="pt-6">
                    <Form {...form}>
                        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                            <FormField
                                control={form.control}
                                name="site_name"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Nama Sistem</FormLabel>
                                        <FormControl>
                                            <Input {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="company_address"
                                render={({ field }) => (
                                    <FormItem>
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
                                name="company_logo_url"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>URL Logo (opsional)</FormLabel>
                                        <FormControl>
                                            <Input {...field} placeholder="https://..." />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <Button type="submit" disabled={form.formState.isSubmitting}>
                                Simpan Pengaturan
                            </Button>
                        </form>
                    </Form>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
