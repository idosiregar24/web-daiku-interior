import { PasswordInput } from '@/Components/shared/PasswordInput';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { Input } from '@/Components/ui/input';
import { PageHeader } from '@/Components/shared/PageHeader';
import { type IdentityValues, UserIdentityFields } from '@/Components/modules/users/UserIdentityFields';
import { optionalEmailField, requireUsernameOrEmail, usernameField } from '@/lib/username';
import AppLayout from '@/Layouts/AppLayout';
import type { Role } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import { type Control, type UseFormSetValue, useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors StoreUserRequest + ValidatesUserIdentity: username and/or email (at least one).
const schema = z
    .object({
        name: z.string().min(1, 'Nama wajib diisi'),
        username: usernameField(),
        email: optionalEmailField,
        password: z.string().min(8, 'Password minimal 8 karakter'),
        role: z.string().min(1, 'Role wajib dipilih'),
    })
    .superRefine(requireUsernameOrEmail);

type FormValues = z.infer<typeof schema>;

export default function CreateUser({ roles }: { roles: Role[] }) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { name: '', username: '', email: '', password: '', role: '' },
    });

    function onSubmit(values: FormValues) {
        router.post(route('users.store'), values, {
            onError: (errors) => {
                Object.entries(errors).forEach(([field, message]) => {
                    form.setError(field as keyof FormValues, { message: message as string });
                });
            },
        });
    }

    return (
        <AppLayout
            breadcrumbs={[{ label: 'Tambah User' }]}
        >
            <Head title="Tambah User" />

            <PageHeader title="Tambah User" icon={UserPlus} description="Buat akun baru dan tentukan role RBAC-nya." />

            <Card className="max-w-lg">
                <CardContent className="px-5 py-2 sm:px-6">
                    <Form {...form}>
                        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                            <FormField
                                control={form.control}
                                name="name"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Nama</FormLabel>
                                        <FormControl>
                                            <Input {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <UserIdentityFields
                                control={form.control as unknown as Control<IdentityValues>}
                                setValue={form.setValue as unknown as UseFormSetValue<IdentityValues>}
                            />
                            <FormField
                                control={form.control}
                                name="password"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Password</FormLabel>
                                        <FormControl>
                                            <PasswordInput autoComplete="new-password" {...field} />
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
                                        <FormLabel required>Role</FormLabel>
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
                            <Button type="submit" disabled={form.formState.isSubmitting}>
                                Simpan
                            </Button>
                        </form>
                    </Form>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
