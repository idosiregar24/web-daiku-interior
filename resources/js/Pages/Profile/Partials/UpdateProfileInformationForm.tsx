import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { UsernameInput } from '@/Components/shared/UsernameInput';
import type { PageProps } from '@/types';
import { Transition } from '@headlessui/react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { format, parseISO } from 'date-fns';
import { id as localeId } from 'date-fns/locale';
import { Lock } from 'lucide-react';
import { FormEventHandler } from 'react';

/**
 * Profil Saya — Sprint 21 (K1/K4): the user sets their own username (once
 * per `usernameChangeDays`, the first one always) and email (any time);
 * at least one stays filled (ValidatesUserIdentity).
 */
export default function UpdateProfileInformation({
    mustVerifyEmail,
    status,
    usernameChangeableAt,
    usernameChangeDays,
    className = '',
}: {
    mustVerifyEmail: boolean;
    status?: string;
    usernameChangeableAt: string | null;
    usernameChangeDays: number;
    className?: string;
}) {
    const user = usePage<PageProps>().props.auth.user!;
    const usernameLocked = usernameChangeableAt !== null;

    const { data, setData, patch, errors, processing, recentlySuccessful } =
        useForm({
            name: user.name,
            username: user.username ?? '',
            email: user.email ?? '',
        });

    const bothEmpty = data.username.trim() === '' && data.email.trim() === '';

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        patch(route('profile.update'), { preserveScroll: true });
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-base font-semibold text-foreground">
                    Informasi Akun
                </h2>

                <p className="mt-1 text-sm text-muted-foreground">
                    Nama, username, dan email Anda. Masuk bisa memakai username atau email.
                </p>
            </header>

            <form onSubmit={submit} className="mt-6 space-y-6">
                <div>
                    <InputLabel htmlFor="name" value="Nama" required />

                    <TextInput
                        id="name"
                        className="mt-1 block w-full"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        required
                        autoComplete="name"
                    />

                    <InputError className="mt-2" message={errors.name} />
                </div>

                <div>
                    <InputLabel htmlFor="username" value="Username" required={bothEmpty} />

                    <div className="mt-1">
                        <UsernameInput
                            id="username"
                            value={data.username}
                            onChange={(value) => setData('username', value)}
                            userId={user.id}
                            personName={data.name}
                            disabled={usernameLocked}
                            placeholder="mis. budisantoso"
                        />
                    </div>

                    {usernameLocked ? (
                        <p className="mt-2 flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Lock className="size-3.5" aria-hidden />
                            Bisa diganti lagi pada {format(parseISO(usernameChangeableAt), 'd MMMM yyyy', { locale: localeId })}.
                        </p>
                    ) : (
                        <p className="mt-2 text-xs text-muted-foreground">
                            Huruf kecil dan angka saja. Username hanya bisa diganti sekali per {usernameChangeDays} hari.
                        </p>
                    )}

                    <InputError className="mt-2" message={errors.username} />
                </div>

                <div>
                    <InputLabel htmlFor="email" value="Email" required={bothEmpty} />

                    <TextInput
                        id="email"
                        type="email"
                        className="mt-1 block w-full"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        autoCapitalize="none"
                        autoComplete="email"
                        placeholder="nama@email.com"
                    />

                    <p className="mt-2 text-xs text-muted-foreground">
                        Dipakai untuk masuk dan untuk "Lupa password". Isi username, email, atau keduanya.
                    </p>

                    <InputError className="mt-2" message={errors.email} />
                </div>

                {mustVerifyEmail && user.email && user.email_verified_at === null && (
                    <div>
                        <p className="mt-2 text-sm text-foreground">
                            Email Anda belum diverifikasi.{' '}
                            <Link
                                href={route('verification.send')}
                                method="post"
                                as="button"
                                className="rounded-md text-sm text-muted-foreground underline-offset-2 hover:text-foreground hover:underline focus:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
                            >
                                Kirim ulang email verifikasi.
                            </Link>
                        </p>

                        {status === 'verification-link-sent' && (
                            <div className="mt-2 rounded-lg bg-success/10 px-3 py-2 text-sm font-medium text-success-ink">
                                Link verifikasi baru sudah dikirim ke email Anda.
                            </div>
                        )}
                    </div>
                )}

                <div className="flex items-center gap-4">
                    <PrimaryButton disabled={processing}>Simpan</PrimaryButton>

                    <Transition
                        show={recentlySuccessful}
                        enter="transition ease-in-out"
                        enterFrom="opacity-0"
                        leave="transition ease-in-out"
                        leaveTo="opacity-0"
                    >
                        <p className="text-sm text-muted-foreground">
                            Tersimpan.
                        </p>
                    </Transition>
                </div>
            </form>
        </section>
    );
}
