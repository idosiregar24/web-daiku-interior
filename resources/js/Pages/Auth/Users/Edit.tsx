import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { Switch } from '@/Components/ui/switch';
import { PageHeader } from '@/Components/shared/PageHeader';
import { PasswordInput } from '@/Components/shared/PasswordInput';
import { type IdentityValues, UserIdentityFields } from '@/Components/modules/users/UserIdentityFields';
import { optionalEmailField, requireUsernameOrEmail, usernameField } from '@/lib/username';
import AppLayout from '@/Layouts/AppLayout';
import type { Role, User } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { UserCog } from 'lucide-react';
import { type Control, type UseFormSetValue, useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors UpdateUserRequest + ValidatesUserIdentity — the user's current
// username is accepted as is (it may predate the format rules).
function schemaFor(currentUsername: string | null) {
    return z
        .object({
            name: z.string().min(1, 'Nama wajib diisi'),
            username: usernameField(currentUsername),
            email: optionalEmailField,
            password: z.string().min(8, 'Password minimal 8 karakter').optional().or(z.literal('')),
            role: z.string().min(1, 'Role wajib dipilih'),
            is_active: z.boolean(),
        })
        .superRefine(requireUsernameOrEmail);
}

type FormValues = z.infer<ReturnType<typeof schemaFor>>;

interface EditUserProps {
    user: Omit<User, 'roles'> & { roles: { id: number; name: Role }[]; assignable_role: Role | null };
    roles: Role[];
}

export default function EditUser({ user, roles }: EditUserProps) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schemaFor(user.username)),
        defaultValues: {
            name: user.name,
            username: user.username ?? '',
            email: user.email ?? '',
            password: '',
            role: user.assignable_role ?? '',
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
            breadcrumbs={[{ label: user.name }, { label: 'Edit' }]}
        >
            <Head title={`Edit ${user.name}`} />

            <PageHeader title="Edit User" icon={UserCog} description={`Perbarui data dan role untuk ${user.name}.`} />

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
                                userId={user.id}
                            />
                            <FormField
                                control={form.control}
                                name="password"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Password baru</FormLabel>
                                        <FormControl>
                                            <PasswordInput autoComplete="new-password" placeholder="Kosongkan jika tidak diubah" {...field} />
                                        </FormControl>
                                        <FormDescription>
                                            Untuk staf yang lupa password. Password ini sementara — {user.name} wajib membuat password sendiri saat masuk berikutnya.
                                        </FormDescription>
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
