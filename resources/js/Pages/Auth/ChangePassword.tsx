import { Notice } from '@/Components/shared/Notice';
import { PasswordInput } from '@/Components/shared/PasswordInput';
import { RequiredMark } from '@/Components/shared/RequiredMark';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import AuthLayout from '@/Layouts/AuthLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

/**
 * Sprint 21 Sub 05 — the only page open after the CEO reset this user's
 * password (EnsurePasswordChanged): make a password of their own, or log
 * out. The temporary password isn't asked again — they just used it.
 */
export default function ChangePassword({ account }: { account: string }) {
    const { data, setData, put, processing, errors, reset } = useForm({
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        put(route('password.update'), {
            onError: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout title="Buat Password Baru" description={`Akun ${account}`}>
            <Head title="Buat Password Baru" />

            <Notice tone="warning" className="mb-5">
                Password Anda diatur ulang oleh CEO. Buat password baru untuk melanjutkan.
            </Notice>

            <form onSubmit={submit} className="space-y-5">
                <div className="space-y-2">
                    <Label htmlFor="password">Password baru<RequiredMark /></Label>
                    <PasswordInput
                        id="password"
                        name="password"
                        value={data.password}
                        autoComplete="new-password"
                        autoFocus
                        className="h-11"
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <p className="text-xs text-muted-foreground">Minimal 8 karakter.</p>
                    {errors.password && <p className="text-sm text-error-ink">{errors.password}</p>}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="password_confirmation">Ulangi password baru<RequiredMark /></Label>
                    <PasswordInput
                        id="password_confirmation"
                        name="password_confirmation"
                        value={data.password_confirmation}
                        autoComplete="new-password"
                        className="h-11"
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                    />
                </div>

                <Button type="submit" className="h-11 w-full shadow-md shadow-daiku-yellow-dark/20" disabled={processing}>
                    Simpan Password
                </Button>

                <p className="text-center text-sm text-muted-foreground">
                    <Link
                        href={route('logout')}
                        method="post"
                        as="button"
                        className="font-medium text-foreground underline decoration-daiku-yellow decoration-2 underline-offset-4 hover:decoration-daiku-yellow-dark"
                    >
                        Keluar
                    </Link>
                </p>
            </form>
        </AuthLayout>
    );
}
