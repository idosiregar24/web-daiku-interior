import { PasswordInput } from '@/Components/shared/PasswordInput';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import AuthLayout from '@/Layouts/AuthLayout';
import type { PageProps } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { RequiredMark } from '@/Components/shared/RequiredMark';

export default function Login({
    status,
    canResetPassword,
}: {
    status?: string;
    canResetPassword: boolean;
}) {
    const { site } = usePage<PageProps>().props;
    const { data, setData, post, processing, errors, reset } = useForm({
        login: '',
        password: '',
        // Sprint 13 D6 — ticked by default so a Tukang doesn't sign in on
        // their phone every morning; untick it on a shared computer.
        remember: true as boolean,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <AuthLayout title="Masuk" description={`Masuk ke akun ${site.name} ${site.tagline} Anda.`}>
            <Head title="Masuk" />

            {status && (
                <div className="mb-4 rounded-lg bg-success/10 px-3 py-2 text-sm font-medium text-success-ink">
                    {status}
                </div>
            )}

            <form onSubmit={submit} className="space-y-5">
                <div className="space-y-2">
                    {/* Sprint 21 (K2) — email or username in one field; "@" decides which (LoginRequest). */}
                    <Label htmlFor="login">Email atau Username<RequiredMark /></Label>
                    <Input
                        id="login"
                        type="text"
                        name="login"
                        value={data.login}
                        autoComplete="username"
                        autoCapitalize="none"
                        autoCorrect="off"
                        spellCheck={false}
                        autoFocus
                        placeholder="nama@email.com atau username"
                        className="h-11"
                        onChange={(e) => setData('login', e.target.value)}
                    />
                    {errors.login && (
                        <p className="text-sm text-error-ink">{errors.login}</p>
                    )}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="password">Password<RequiredMark /></Label>
                    <PasswordInput
                        id="password"
                        name="password"
                        value={data.password}
                        autoComplete="current-password"
                        className="h-11"
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    {errors.password && (
                        <p className="text-sm text-error-ink">{errors.password}</p>
                    )}
                </div>

                <div className="flex items-center justify-between">
                    <label className="flex items-center gap-2 text-sm text-daiku-muted">
                        <Checkbox
                            name="remember"
                            checked={data.remember}
                            onCheckedChange={(checked) => setData('remember', checked === true)}
                        />
                        Ingat saya
                    </label>

                    {canResetPassword ? (
                        <Link
                            href={route('password.request')}
                            className="text-sm font-medium text-foreground underline decoration-daiku-yellow decoration-2 underline-offset-4 hover:decoration-daiku-yellow-dark"
                        >
                            Lupa password?
                        </Link>
                    ) : (
                        <span className="text-sm text-daiku-muted">Lupa password? Minta CEO.</span>
                    )}
                </div>

                <Button type="submit" className="h-11 w-full shadow-md shadow-daiku-yellow-dark/20" disabled={processing}>
                    Masuk
                </Button>
            </form>
        </AuthLayout>
    );
}
