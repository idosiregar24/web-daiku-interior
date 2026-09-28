import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import AuthLayout from '@/Layouts/AuthLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function ForgotPassword({ status }: { status?: string }) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('password.email'));
    };

    return (
        <AuthLayout
            title="Lupa Password"
            description="Forgot your password? No problem. Just let us know your email address and we will email you a password reset link that will allow you to choose a new one."
        >
            <Head title="Forgot Password" />

            {status && (
                <div className="mb-4 rounded-lg bg-success/10 px-3 py-2 text-sm font-medium text-success-ink">
                    {status}
                </div>
            )}

            <form onSubmit={submit} className="space-y-5">
                <div className="space-y-2">
                    <Label htmlFor="email">Email</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        autoFocus
                        placeholder="nama@daikuinterior.com"
                        className="h-11"
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    {errors.email && <p className="text-sm text-error-ink">{errors.email}</p>}
                </div>

                <Button type="submit" className="h-11 w-full shadow-md shadow-daiku-yellow-dark/20" disabled={processing}>
                    Email Password Reset Link
                </Button>

                <p className="text-center text-sm text-muted-foreground">
                    <Link
                        href={route('login')}
                        className="font-medium text-foreground underline decoration-daiku-yellow decoration-2 underline-offset-4 hover:decoration-daiku-yellow-dark"
                    >
                        Kembali ke halaman Masuk
                    </Link>
                </p>
            </form>
        </AuthLayout>
    );
}
