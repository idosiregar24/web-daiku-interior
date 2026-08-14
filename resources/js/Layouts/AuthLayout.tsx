import ApplicationLogo from '@/Components/ApplicationLogo';
import { Link } from '@inertiajs/react';
import { PropsWithChildren } from 'react';

export default function AuthLayout({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-daiku-cream px-4 py-10">
            <Link href="/" className="mb-6 flex items-center gap-2.5">
                <span className="flex size-10 items-center justify-center rounded-md bg-daiku-yellow">
                    <ApplicationLogo className="size-6 fill-daiku-dark" />
                </span>
                <span className="text-base font-semibold tracking-tight text-daiku-dark">
                    Daiku Interior
                </span>
            </Link>

            <div className="w-full overflow-hidden rounded-lg border border-daiku-border bg-white px-6 py-6 shadow-sm sm:max-w-md">
                {children}
            </div>
        </div>
    );
}
