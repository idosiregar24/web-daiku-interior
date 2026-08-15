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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { Switch } from '@/Components/ui/switch';
import { PageHeader } from '@/Components/shared/PageHeader';
import AppLayout from '@/Layouts/AppLayout';
import type { Role, User } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const schema = z.object({
    name: z.string().min(1, 'Nama wajib diisi'),
    email: z.string().min(1, 'Email wajib diisi').email('Format email tidak valid'),
    password: z.string().min(8, 'Password minimal 8 karakter').optional().or(z.literal('')),
    role: z.string().min(1, 'Role wajib dipilih'),
    is_active: z.boolean(),
});

type FormValues = z.infer<typeof schema>;

interface EditUserProps {
    user: User & { roles: { id: number; name: Role }[] };
    roles: Role[];
}

export default function EditUser({ user, roles }: EditUserProps) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: {
            name: user.name,
            email: user.email,
            password: '',
            role: user.roles[0]?.name ?? '',
            is_active: user.is_active ?? true,
        },
    });

    function onSubmit(values: FormValues) {
        router.put(
            route('users.update', user.id),
            { ...values, password: values.password || undefined },
            {
                onError: (errors) => {
                    Object.entries(errors).forEach(([field, message]) => {
                        form.setError(field as keyof FormValues, { message: message as string });
                    });
                },
            },
        );
    }

    return (
        <AppLayout
            breadcrumbs={[
                { label: 'User Management', routeName: 'users.index' },
                { label: 'Edit User' },
            ]}
        >
            <Head title={`Edit ${user.name}`} />

            <PageHeader title="Edit User" description={`Perbarui data dan role untuk ${user.name}.`} />

            <Card className="max-w-lg">
                <CardContent className="pt-6">
                    <Form {...form}>
                        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                            <FormField
                                control={form.control}
                                name="name"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Nama</FormLabel>
                                        <FormControl>
                                            <Input {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="email"
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
                                name="password"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Password baru (opsional)</FormLabel>
                                        <FormControl>
                                            <Input type="password" placeholder="Kosongkan jika tidak diubah" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="role"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Role</FormLabel>
                                        <Select onValueChange={field.onChange} value={field.value}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Pilih role" />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                {roles.map((role) => (
                                                    <SelectItem key={role} value={role}>
                                                        {role}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="is_active"
                                render={({ field }) => (
                                    <FormItem className="flex flex-row items-center justify-between rounded-lg border border-daiku-border p-3">
                                        <FormLabel className="cursor-pointer">Akun aktif</FormLabel>
                                        <FormControl>
                                            <Switch checked={field.value} onCheckedChange={field.onChange} />
                                        </FormControl>
                                    </FormItem>
                                )}
                            />
                            <Button type="submit" disabled={form.formState.isSubmitting}>
                                Simpan Perubahan
                            </Button>
                        </form>
                    </Form>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
